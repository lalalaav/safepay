<?php

namespace App\Http\Controllers;

use App\Models\BlockchainTransaction;
use App\Models\Dispute;
use App\Models\Escrow;
use App\Models\Order;
use App\Models\User;
use App\Models\Wallet;
use App\Services\ChainReader;
use App\Services\PaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Satu controller untuk seluruh alur demo. Server hanya baca blockchain;
 * setiap transaksi tulis ditandatangani MetaMask di browser, lalu hash-nya
 * dikirim ke sini untuk diverifikasi dan dicatat.
 */
class SafePayController extends Controller
{
    /** Batas waktu escrow default: 30 hari. */
    private const DEADLINE = 30 * 86400;

    /** Aksi mode manual -> event kontrak yang dihasilkan. */
    private const EVENT_FOR = [
        'pay' => 'PaymentCreated',
        'ship' => 'Shipped',
        'confirm' => 'Released',
        'refund' => 'Refunded',
        'dispute' => 'Disputed',
        'resolve' => 'DisputeResolved',
    ];

    public function __construct(
        private ChainReader $reader,
        private PaymentService $chain,
    ) {}

    public function home()
    {
        return redirect()->route(Auth::check() ? 'dashboard' : 'login');
    }

    public function login()
    {
        return view('auth.login');
    }

    public function authenticate(Request $request)
    {
        $cred = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($cred)) {
            return back()->with('error', 'Email atau password salah.');
        }

        $request->session()->regenerate();

        return redirect()->route('dashboard');
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    public function dashboard()
    {
        $user = Auth::user();

        $orders = Order::with(['buyer.wallet', 'seller.wallet', 'escrow.transactions'])
            ->when($user->hasRole('buyer'), fn ($q) => $q->where('buyer_id', $user->id))
            ->when($user->hasRole('seller'), fn ($q) => $q->where('seller_id', $user->id))
            ->latest()
            ->get();

        return view('dashboard', [
            'user' => $user,
            'orders' => $this->attachChain($orders),
            'sellers' => User::where('role', 'seller')->get(),
            'mode' => config('safepay.mode'),
            // Dipakai tombol Bayar: satu deadline Unix yang masih berlaku.
            'deadline' => now()->addSeconds(self::DEADLINE)->timestamp,
            'chainBalance' => $this->safeBalance(),
            // Saldo pengguna dibaca dari kontrak token, bukan dari MySQL.
            'myBalance' => $user->address() ? $this->safeBalanceOf($user->address()) : null,
        ]);
    }

    /** Saldo mIDR satu wallet. Null kalau node tidak bisa dibaca. */
    private function safeBalanceOf(?string $holder): ?int
    {
        if (! $holder) {
            return null;
        }

        try {
            return $this->reader->tokenBalance($holder);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Tempelkan status asli dari smart contract pada tiap escrow yang sudah punya
     * transaksi tercatat. Dashboard menampilkan dua kolom status supaya bedanya
     * jelas: MySQL cuma cermin, kontrak yang menentukan.
     */
    private function attachChain($orders)
    {
        return $orders->each(function (Order $order) {
            $escrow = $order->escrow;

            // Pembayaran pertama berkontrak id 0, jadi "sudah terhubung" ditentukan
            // dari ada/tidaknya baris escrow — bukan dari nilai idnya.
            if ($escrow) {
                $escrow->setAttribute('chain', $this->chainPayment((int) $escrow->contract_id));
            }
        });
    }

    /** Simpan alamat wallet yang tersambung dari MetaMask. */
    public function bindWallet(Request $request)
    {
        $data = $request->validate([
            'wallet_address' => ['required', 'regex:/^0x[a-fA-F0-9]{40}$/'],
        ]);

        Wallet::updateOrCreate(
            ['user_id' => Auth::id()],
            ['wallet_address' => strtolower($data['wallet_address'])],
        );

        return response()->json(['ok' => true]);
    }

    public function storeOrder(Request $request)
    {
        $data = $request->validate([
            'seller_id' => ['required', 'exists:users,id', 'different:'.Auth::id()],
            'product_name' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'integer', 'min:1'],
        ]);

        $order = Order::create($data + ['buyer_id' => Auth::id()]);

        return redirect()->route('dashboard')
            ->with('status', "Pesanan #{$order->id} dibuat. Lanjut tekan Bayar & kunci dana.");
    }

    /**
     * Hapus pesanan. Hanya pembeli pemilik, dan hanya sebelum dana masuk kontrak.
     * Setelah dibayar, dana nyata terkunci di smart contract — menghapus baris
     * MySQL tidak mengembalikannya, jadi ditolak dan diarahkan ke refund/sengketa.
     */
    public function destroy(Order $order)
    {
        abort_unless($order->buyer_id === Auth::id(), 403, 'Hanya pembeli pemilik pesanan yang boleh menghapus.');

        if ($order->escrow) {
            return back()->with('error', "Pesanan #{$order->id} sudah dibayar — dana terkunci di smart contract dan tidak bisa dihapus. Pakai Refund atau ajukan sengketa.");
        }

        $order->delete();

        return back()->with('status', "Pesanan #{$order->id} dihapus. Belum ada transaksi blockchain, jadi tidak ada yang perlu dibatalkan on-chain.");
    }

    /**
     * Izinkan aksi hanya pada peran dan pesanan yang tepat. Auditor hanya
     * membaca explorer; ia tidak pernah bisa mengirim transaksi escrow.
     */
    private function authorizeAction(Order $order, string $action): void
    {
        $user = Auth::user();

        $allowed = match ($action) {
            'pay' => $user->hasRole('buyer') && $order->buyer_id === $user->id,
            'confirm', 'refund', 'dispute' => $user->hasRole('buyer') && $order->buyer_id === $user->id,
            'ship' => $user->hasRole('seller') && $order->seller_id === $user->id,
            'resolve' => $user->hasRole('validator') && strtolower((string) $user->address()) === strtolower((string) config('safepay.arbiter')),
            default => false,
        };

        abort_unless($allowed, 403, 'Peran ini tidak berwenang untuk aksi tersebut.');
    }

    /**
     * Catat hash transaksi MetaMask. Sebelum disimpan, hash diverifikasi ke
     * blockchain: kalau hash tidak ada, gagal, atau bukan dari kontrak escrow,
     * baris ini ditolak. Inilah bagian anti-manipulasi yang ditunjukkan ke dosen.
     */
    /**
     * MODE MANUAL: server menjalankan aksi escrow memakai akun Hardhat milik
     * pelaku, lalu hash aslinya dicatat lewat jalur yang sama dengan mode wallet.
     *
     * Ini yang membuat tombol Validator dan tombol lain hidup tanpa MetaMask,
     * jadi HP pun bisa memakainya. Dana tetap benar-benar berpindah di kontrak.
     */
    public function manualAction(Request $request, Order $order)
    {
        abort_unless(config('safepay.mode') === 'manual', 404);

        $data = $request->validate([
            'action' => ['required', 'in:'.implode(',', array_keys(self::EVENT_FOR))],
            'reason' => ['nullable', 'string', 'max:255'],
            'to_seller' => ['nullable', 'boolean'],
            'fast_refund' => ['nullable', 'boolean'],
            'deadline_seconds' => ['nullable', 'integer', 'min:30', 'max:2592000'],
        ]);

        $action = $data['action'];
        $this->authorizeAction($order, $action);

        // contract_id 0 SAH (pembayaran pertama), jadi status "sudah terhubung"
        // diperiksa dari adanya baris escrow, bukan dari nilai id.
        $linked = $order->escrow !== null;

        if ($action === 'pay' && $linked) {
            return back()->with('error', 'Pesanan ini sudah dibayar dan tercatat on-chain.');
        }

        if ($action !== 'pay' && ! $linked) {
            return back()->with('error', 'Pesanan belum terhubung ke smart contract. Bayar dulu.');
        }

        $params = [
            'buyer' => $order->buyer?->address(),
            'seller' => $order->seller?->address(),
            'arbiter' => config('safepay.arbiter'),
            'amount' => (string) $order->amount,
            'contract_id' => (int) ($order->escrow?->contract_id ?? 0),
            'to_seller' => $request->boolean('to_seller'),
            'reason' => $data['reason'] ?? null,
        ];

        // Refund hanya bisa setelah deadline lewat. Untuk demo, deadline 2 menit
        // lebih berguna daripada menunggu 30 hari.
        if ($action === 'pay' && $request->boolean('fast_refund')) {
            $params['deadline_seconds'] = $data['deadline_seconds'] ?? 120;
        }

        if (in_array(null, [$params['buyer'], $params['seller']], true)) {
            return back()->with('error', 'Wallet pelaku belum terdaftar. Jalankan: php artisan db:seed');
        }

        try {
            $result = $this->chain->run($action, $params);
        } catch (\Throwable $e) {
            return back()->with('error', 'Aksi blockchain gagal: '.$e->getMessage());
        }

        return $this->record($order, $result['tx_hash'], self::EVENT_FOR[$action], [
            'contract_id' => $result['contract_id'] ?? null,
            'deadline' => $result['deadline'] ?? null,
            'reason' => $data['reason'] ?? null,
        ]);
    }

    public function recordTransaction(Request $request, Order $order)
    {
        $data = $request->validate([
            'tx_hash' => ['required', 'regex:/^0x[a-fA-F0-9]{64}$/'],
            'event_type' => ['required', 'in:'.implode(',', array_keys(ChainReader::TOPIC))],
            'deadline' => ['nullable', 'integer', 'min:1'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $event = $data['event_type'];

        // Yang boleh mencatat hanya pelaku yang berwenang atas aksi itu.
        // Auditor tetap baca-saja: POST dari akunnya ditolak 403.
        $this->authorizeRecording($order, $event);

        try {
            if ($event === 'PaymentCreated') {
                if ($order->escrow) {
                    return back()->with('error', "Pesanan #{$order->id} sudah punya escrow on-chain. Pembayaran kedua tidak dicatat.");
                }

                // id pembayaran diambil dari log kontrak, bukan dari form:
                // nilai kiriman klien tidak pernah dipercaya.
                $id = $this->reader->paymentIdFromReceipt($data['tx_hash']);

                if ($id === null) {
                    return back()->with('error', "Verifikasi blockchain GAGAL untuk PaymentCreated {$data['tx_hash']}. Hash tidak memuat event PaymentCreated yang sah dari kontrak escrow.");
                }

                $data['contract_id'] = $id;
            } else {
                $escrow = $order->escrow;

                if (! $escrow) {
                    return back()->with('error', 'Pesanan belum terhubung ke smart contract. Bayar dulu.');
                }

                $data['contract_id'] = (int) $escrow->contract_id;

                // Hash sah milik pesanan LAIN ditolak: event harus menyebut
                // escrow id pesanan ini.
                $valid = $this->reader->verify(
                    $data['tx_hash'],
                    [ChainReader::TOPIC[$event]],
                    (int) $escrow->contract_id,
                );

                if (! $valid) {
                    return back()->with('error', "Verifikasi blockchain GAGAL untuk {$event} {$data['tx_hash']}. Tidak ada event {$event} untuk escrow id {$escrow->contract_id} di kontrak.");
                }
            }
        } catch (\Throwable $e) {
            return back()->with('error', 'Node blockchain tidak bisa dihubungi: '.$e->getMessage());
        }

        return $this->record($order, $data['tx_hash'], $event, $data);
    }

    /** event_type -> aksi, supaya otorisasi pencatatan memakai aturan yang sama. */
    private function authorizeRecording(Order $order, string $event): void
    {
        $action = array_search($event, self::EVENT_FOR, true);

        abort_if($action === false, 422, 'Event tidak dikenal.');

        $this->authorizeAction($order, $action);
    }

    /**
     * Satu jalur pencatatan untuk kedua mode: escrow, status, dispute, riwayat tx.
     * Status akhirnya selalu dibaca ulang dari kontrak, bukan ditebak dari event.
     */
    private function record(Order $order, string $txHash, string $event, array $extra)
    {
        $escrow = $order->escrow ?? Escrow::create([
            'order_id' => $order->id,
            'contract_id' => $extra['contract_id'] ?? 0,
            'status' => 'CREATED',
        ]);

        $escrow->update([
            'contract_id' => $extra['contract_id'] ?? $escrow->contract_id,
            'deadline' => $extra['deadline'] ?? $escrow->deadline,
            'status' => $this->chainStatus($escrow) ?? self::statusFor($event) ?? $escrow->status,
        ]);

        if ($event === 'Disputed') {
            Dispute::updateOrCreate(
                ['escrow_id' => $escrow->id],
                ['reason' => ($extra['reason'] ?? null) ?: 'Diajukan pembeli', 'status' => 'OPEN'],
            );
        }

        if ($event === 'DisputeResolved') {
            $escrow->dispute?->update(['status' => 'RESOLVED', 'resolution' => 'Diputus validator']);
        }

        $receipt = $this->reader->receipt($txHash);

        BlockchainTransaction::updateOrCreate(
            ['tx_hash' => strtolower($txHash)],
            [
                'escrow_id' => $escrow->id,
                'block_number' => isset($receipt['blockNumber']) ? hexdec($receipt['blockNumber']) : null,
                'event_type' => $event,
            ],
        );

        return redirect()->route('dashboard')->with('status', sprintf(
            '%s tercatat on-chain. Status kontrak: %s. Hash: %s',
            $event,
            $this->chainStatus($escrow) ?? 'tidak terbaca',
            $txHash,
        ));
    }

    /** payments(id) dari kontrak. Id 0 SAH — itu pembayaran pertama. */
    private function chainPayment(int $id): ?array
    {
        try {
            return $this->reader->payment($id);
        } catch (\Throwable) {
            return null;
        }
    }

    /** Status sebenarnya dari smart contract. Null kalau node tidak bisa dibaca. */
    private function chainStatus(Escrow $escrow): ?string
    {
        return $this->chainPayment((int) $escrow->contract_id)['status'] ?? null;
    }

    public function explorer(Request $request)
    {
        $txs = BlockchainTransaction::with('escrow.order.buyer', 'escrow.order.seller')
            ->when($request->filled('tx'), fn ($q) => $q->where('tx_hash', 'like', '%'.$request->query('tx').'%'))
            ->latest()
            ->get();

        $escrows = Escrow::with('order')->orderBy('contract_id')->get()
            ->each(fn (Escrow $escrow) => $escrow->setAttribute(
                'chain',
                $this->chainPayment((int) $escrow->contract_id),
            ));

        return view('explorer', [
            'txs' => $txs,
            'escrows' => $escrows,
            'chainBalance' => $this->safeBalance(),
            'query' => $request->query('tx'),
        ]);
    }

    /** Halaman pembuktian: cocokkan hash di MySQL dengan yang ada di blockchain. */
    public function verify(string $txHash)
    {
        $record = BlockchainTransaction::with('escrow.order')->where('tx_hash', strtolower($txHash))->first();

        $onChain = null;
        $error = null;

        try {
            $onChain = $this->reader->receipt($txHash);
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        return view('verify', [
            'record' => $record,
            'txHash' => $txHash,
            'onChain' => $onChain,
            'error' => $error,
            'escrow' => config('safepay.escrow'),
        ]);
    }

    private static function statusFor(string $event): ?string
    {
        return match ($event) {
            'PaymentCreated' => 'FUNDED',
            'Shipped' => 'SHIPPED',
            'Released' => 'RELEASED',
            'Refunded' => 'REFUNDED',
            'Disputed' => 'DISPUTED',
            // DisputeResolved bisa RELEASED atau REFUNDED: wajib dari kontrak.
            default => null,
        };
    }

    /** ChainReader melempar kalau node Hardhat mati; dashboard tetap harus terbuka. */
    private function safeBalance(): ?int
    {
        try {
            return $this->reader->escrowBalance();
        } catch (\Throwable) {
            return null;
        }
    }
}
