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

    public function register()
    {
        return view('auth.register');
    }

    public function storeUser(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:6', 'confirmed'],
            'role' => ['required', 'in:buyer,seller,auditor'],
            'wallet_address' => ['nullable', 'regex:/^0x[a-fA-F0-9]{40}$/', 'unique:wallets,wallet_address'],
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
            'role' => $data['role'],
        ]);

        if (! empty($data['wallet_address'])) {
            Wallet::create([
                'user_id' => $user->id,
                'wallet_address' => strtolower($data['wallet_address']),
            ]);
        }

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('dashboard')
            ->with('status', "Akun {$user->name} ({$user->role}) berhasil dibuat. Sambungkan MetaMask untuk mulai bertransaksi.");
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

        $orders = Order::with(['buyer.wallet', 'seller.wallet', 'escrow.transactions', 'escrow.dispute'])
            ->when($user->hasRole('buyer'), fn ($q) => $q->where('buyer_id', $user->id))
            ->when($user->hasRole('seller'), fn ($q) => $q->where('seller_id', $user->id))
            ->latest()
            ->get();

        $orders = $this->attachChain($orders);

        $disputes = $user->hasRole(['validator', 'auditor'])
            ? Dispute::with(['escrow.order.buyer.wallet', 'escrow.order.seller.wallet'])
                ->latest()
                ->get()
                ->each(function (Dispute $dispute) {
                    if ($dispute->escrow) {
                        $dispute->escrow->setAttribute('chain', $this->chainPayment((int) $dispute->escrow->contract_id));
                    }
                })
            : collect();

        $sellerHeld = 0;
        $sellerReleased = 0;
        if ($user->hasRole('seller')) {
            foreach ($orders as $order) {
                $st = $order->escrow?->getAttribute('chain')['status'] ?? $order->escrow?->status;
                if (in_array($st, ['FUNDED', 'SHIPPED', 'DISPUTED'], true)) {
                    $sellerHeld += (int) $order->amount;
                } elseif ($st === 'RELEASED') {
                    $sellerReleased += (int) $order->amount;
                }
            }
        }

        $health = $this->reader->health();
        $myBalance = ($health['node_ok'] && $user->address()) ? $this->safeBalanceOf($user->address()) : null;
        $chainBalance = $health['deployed'] ? $this->safeBalance() : null;

        return view('dashboard', [
            'user' => $user,
            'orders' => $orders,
            'disputes' => $disputes,
            'sellers' => User::with('wallet')->where('role', 'seller')->get(),
            'mode' => config('safepay.mode'),
            // Dipakai tombol Bayar: satu deadline Unix yang masih berlaku.
            'deadline' => now()->addSeconds(self::DEADLINE)->timestamp,
            'chainBalance' => $chainBalance,
            'escrowEth' => $chainBalance !== null ? ChainReader::idrToEth($chainBalance) : null,
            // Saldo ETH pengguna dibaca langsung dari node blockchain, bukan dari MySQL.
            'myBalance' => $myBalance,
            'myEth' => $myBalance !== null ? ChainReader::idrToEth($myBalance) : null,
            'sellerHeld' => $sellerHeld,
            'sellerReleased' => $sellerReleased,
            'health' => $health,
            'txCount' => BlockchainTransaction::count(),
        ]);
    }

    /** Endpoint ringan untuk sinkronisasi real-time antar semua device (Buyer, Seller, Validator, Auditor). */
    public function liveState()
    {
        $user = Auth::user();
        $latestTx = BlockchainTransaction::with('escrow.order')->latest('id')->first();
        $txCount = BlockchainTransaction::count();
        $orderCount = Order::count();
        $orderUpdated = (string) Order::max('updated_at');
        $escrowUpdated = (string) Escrow::max('updated_at');
        $disputeUpdated = (string) Dispute::max('updated_at');
        $disputeOpen = Dispute::where('status', 'OPEN')->count();

        $stateHash = md5(implode('|', [
            $latestTx?->id ?? 0,
            $txCount,
            $orderCount,
            $orderUpdated,
            $escrowUpdated,
            $disputeUpdated,
            $disputeOpen,
        ]));

        $health = $this->reader->health();

        return response()->json([
            'state_hash' => $stateHash,
            'latest_tx_id' => $latestTx?->id ?? 0,
            'tx_count' => $txCount,
            'order_count' => $orderCount,
            'dispute_open_count' => $disputeOpen,
            'latest_event' => $latestTx ? [
                'id' => $latestTx->id,
                'event_type' => $latestTx->event_type,
                'product_name' => $latestTx->escrow?->order?->product_name,
                'amount' => $latestTx->escrow?->order?->amount,
                'tx_hash' => $latestTx->tx_hash,
                'status' => $latestTx->escrow?->status,
                'time' => $latestTx->created_at?->format('H:i:s'),
            ] : null,
            'health' => $health,
            'escrow' => config('safepay.escrow'),
            'chain_balance' => $health['deployed'] ? $this->safeBalance() : null,
            'my_balance' => ($health['node_ok'] && $user?->address()) ? $this->safeBalanceOf($user->address()) : null,
        ]);
    }

    /** Saldo ekuivalen Rp (dari ETH) satu wallet. Null kalau node tidak bisa dibaca. */
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

        $user = Auth::user();
        $addr = strtolower($data['wallet_address']);

        // Cek apakah wallet sudah dimiliki pengguna lain (mencegah error SQLSTATE 1062).
        $existing = Wallet::with('user')
            ->where('wallet_address', $addr)
            ->where('user_id', '!=', $user->id)
            ->first();

        if ($existing) {
            $owner = $existing->user;
            $expected = $user->address();
            $hint = $expected
                ? " Ganti akun di MetaMask ke alamat milik {$user->name} ({$expected})."
                : ' Pilih akun MetaMask lain yang belum dipakai peserta lain.';

            return response()->json([
                'ok' => false,
                'code' => 'WALLET_IN_USE',
                'message' => "Wallet MetaMask aktif ({$addr}) adalah milik {$owner?->name} ({$owner?->role}), sedangkan kamu login sebagai {$user->name} ({$user->role}).{$hint}",
                'expected' => $expected,
            ], 409);
        }

        // Validator harus menggunakan alamat arbiter yang tertanam di smart contract.
        $arbiter = strtolower((string) config('safepay.arbiter'));
        if ($user->hasRole('validator') && $arbiter !== '' && $addr !== $arbiter) {
            return response()->json([
                'ok' => false,
                'code' => 'ARBITER_MISMATCH',
                'message' => "Akun validator harus tersambung ke wallet arbiter kontrak ({$arbiter}), bukan {$addr}.",
                'expected' => $arbiter,
            ], 422);
        }

        Wallet::updateOrCreate(
            ['user_id' => $user->id],
            ['wallet_address' => $addr],
        );

        return response()->json([
            'ok' => true,
            'wallet_address' => $addr,
        ]);
    }

    /** Isi saldo simulasi +1 ETH (= Rp 1.000.000) ke wallet pengguna dari akun cadangan Hardhat. */
    public function faucetWallet(Request $request)
    {
        $user = Auth::user();
        $target = $request->input('wallet_address') ?: $user->address();

        if (! $target || ! preg_match('/^0x[a-fA-F0-9]{40}$/', $target)) {
            return response()->json([
                'ok' => false,
                'message' => 'Hubungkan wallet MetaMask terlebih dahulu sebelum mengisi saldo ETH simulasi.',
            ], 422);
        }

        try {
            $txHash = $this->reader->faucetEth($target, 1);

            return response()->json([
                'ok' => true,
                'tx_hash' => $txHash,
                'wallet_address' => $target,
                'message' => "Berhasil mengirim +1 ETH (setara Rp 1.000.000 simulasi) ke wallet {$target}.",
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'ok' => false,
                'message' => 'Gagal mengirim ETH simulasi dari Hardhat Node: '.$e->getMessage(),
            ], 500);
        }
    }

    public function storeOrder(Request $request)
    {
        abort_unless(Auth::user()->hasRole('buyer'), 403, 'Hanya pembeli yang dapat membuat pesanan.');

        $data = $request->validate([
            'seller_id' => ['required', 'exists:users,id', 'different:'.Auth::id()],
            'product_name' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'integer', 'min:1'],
        ]);

        $seller = User::findOrFail($data['seller_id']);
        if (! $seller->hasRole('seller')) {
            return back()->with('error', 'Penerima pesanan harus akun dengan peran penjual (seller).');
        }

        if (! $seller->address()) {
            return back()->with('error', "Penjual {$seller->name} belum menghubungkan wallet MetaMask.");
        }

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
            'pay', 'confirm', 'refund' => $user->hasRole('buyer') && $order->buyer_id === $user->id,
            'ship' => $user->hasRole('seller') && $order->seller_id === $user->id,
            // Smart contract mengizinkan pembeli ATAU penjual mengajukan sengketa.
            'dispute' => ($user->hasRole('buyer') && $order->buyer_id === $user->id)
                || ($user->hasRole('seller') && $order->seller_id === $user->id),
            'resolve' => $user->hasRole('validator') && strtolower((string) $user->address()) === strtolower((string) config('safepay.arbiter')),
            default => false,
        };

        abort_unless($allowed, 403, 'Peran ini tidak berwenang untuk aksi tersebut.');
    }

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
            'actor' => Auth::user()->address(),
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

    /**
     * Catat hash transaksi MetaMask. Sebelum disimpan, hash diverifikasi ke
     * blockchain: kalau hash tidak ada, gagal, atau bukan dari kontrak escrow,
     * baris ini ditolak. Inilah bagian anti-manipulasi yang ditunjukkan ke dosen.
     */
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

                $existingEscrow = Escrow::with('transactions')->where('contract_id', $id)->first();
                if ($existingEscrow) {
                    $prevHash = $existingEscrow->transactions->firstWhere('event_type', 'PaymentCreated')?->tx_hash;
                    $staleNode = $prevHash ? empty($this->reader->receipt($prevHash)) : false;

                    if ($staleNode) {
                        return back()->with('error', "Escrow id {$id} bentrok dengan data lama di MySQL karena Hardhat Node sempat di-restart. Jalankan 'php artisan migrate:fresh --seed' agar database sinkron dengan node baru.");
                    }

                    return back()->with('error', "Escrow id {$id} dari transaksi {$data['tx_hash']} sudah terhubung ke pesanan lain.");
                }

                $onChain = $this->chainPayment($id);
                if ($onChain) {
                    if ((int) $onChain['amount'] !== (int) $order->amount) {
                        return back()->with('error', 'Nominal on-chain (Rp '.number_format($onChain['amount'], 0, ',', '.').") tidak sesuai dengan pesanan #{$order->id} (Rp ".number_format($order->amount, 0, ',', '.').').');
                    }

                    if ($order->seller?->address() && strtolower($onChain['seller']) !== strtolower($order->seller->address())) {
                        return back()->with('error', "Alamat penjual on-chain ({$onChain['seller']}) tidak sesuai dengan penjual pesanan #{$order->id}.");
                    }

                    if ($order->buyer?->address() && strtolower($onChain['buyer']) !== strtolower($order->buyer->address())) {
                        return back()->with('error', "Alamat pembeli on-chain ({$onChain['buyer']}) tidak sesuai dengan pembeli pesanan #{$order->id}.");
                    }
                }

                $data['contract_id'] = $id;
                if ($onChain && ! empty($onChain['deadline'])) {
                    $data['deadline'] = $onChain['deadline'];
                }
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

        $chainStatus = $this->chainStatus($escrow);

        $escrow->update([
            'contract_id' => $extra['contract_id'] ?? $escrow->contract_id,
            'deadline' => $extra['deadline'] ?? $escrow->deadline,
            'status' => $chainStatus ?? self::statusFor($event) ?? $escrow->status,
        ]);

        if ($event === 'Disputed') {
            $by = Auth::user()?->hasRole('seller') ? 'penjual' : 'pembeli';
            Dispute::updateOrCreate(
                ['escrow_id' => $escrow->id],
                ['reason' => ($extra['reason'] ?? null) ?: "Diajukan {$by}", 'status' => 'OPEN'],
            );
        }

        if ($event === 'DisputeResolved') {
            $resolution = match ($chainStatus) {
                'RELEASED' => 'Dana dilepas ke penjual oleh validator',
                'REFUNDED' => 'Dana dikembalikan ke pembeli oleh validator',
                default => 'Diputus validator',
            };

            Dispute::updateOrCreate(
                ['escrow_id' => $escrow->id],
                ['reason' => $escrow->dispute?->reason ?: 'Sengketa transaksi', 'status' => 'RESOLVED', 'resolution' => $resolution],
            );
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
            $chainStatus ?? 'tidak terbaca',
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
        // Izinkan penguji langsung verifikasi hash apapun (termasuk hash yang tidak ada di MySQL).
        if ($request->filled('verify_tx')) {
            $rawHash = trim((string) $request->query('verify_tx'));
            if (preg_match('/^0x[a-fA-F0-9]{64}$/', $rawHash)) {
                return redirect()->route('explorer.verify', ['tx' => $rawHash]);
            }

            return redirect()->route('explorer')
                ->with('error', 'Format transaction hash tidak valid. Harus diawali 0x dan 64 karakter hex.');
        }

        $txs = BlockchainTransaction::with('escrow.order.buyer', 'escrow.order.seller')
            ->when($request->filled('tx'), fn ($q) => $q->where('tx_hash', 'like', '%'.$request->query('tx').'%'))
            ->latest()
            ->get();

        $escrows = Escrow::with(['order', 'dispute'])->orderBy('contract_id')->get()
            ->each(fn (Escrow $escrow) => $escrow->setAttribute(
                'chain',
                $this->chainPayment((int) $escrow->contract_id),
            ));

        $health = $this->reader->health();

        return view('explorer', [
            'txs' => $txs,
            'escrows' => $escrows,
            'chainBalance' => $health['deployed'] ? $this->safeBalance() : null,
            'health' => $health,
            'query' => $request->query('tx'),
        ]);
    }

    /** Halaman pembuktian: cocokkan hash di MySQL dengan yang ada di blockchain. */
    public function verify(string $txHash)
    {
        $record = BlockchainTransaction::with('escrow.order')->where('tx_hash', strtolower($txHash))->first();

        $onChain = null;
        $decodedEvents = [];
        $chainPayment = null;
        $error = null;

        try {
            $onChain = $this->reader->receipt($txHash);
            if (! empty($onChain)) {
                $decodedEvents = $this->reader->decodeEscrowEvents($onChain);
                $cid = $decodedEvents[0]['contract_id'] ?? $record?->escrow?->contract_id;
                if ($cid !== null) {
                    $chainPayment = $this->chainPayment((int) $cid);
                }
            }
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        $tampered = false;
        $tamperReasons = [];

        if ($record && ! empty($onChain)) {
            $onChainBlock = isset($onChain['blockNumber']) ? (int) hexdec($onChain['blockNumber']) : null;
            if ($record->block_number !== null && $onChainBlock !== null && (int) $record->block_number !== $onChainBlock) {
                $tampered = true;
                $tamperReasons[] = "Nomor block di MySQL ({$record->block_number}) berbeda dengan blockchain ({$onChainBlock}).";
            }

            if ($chainPayment && $record->escrow && $record->escrow->status !== $chainPayment['status']) {
                $tampered = true;
                $tamperReasons[] = "Status escrow di MySQL ({$record->escrow->status}) berbeda dengan Smart Contract ({$chainPayment['status']}).";
            }

            if ($chainPayment && $record->escrow?->order && (int) $record->escrow->order->amount !== (int) $chainPayment['amount']) {
                $tampered = true;
                $tamperReasons[] = 'Nominal pesanan di MySQL (Rp '.number_format($record->escrow->order->amount, 0, ',', '.').') berbeda dengan Smart Contract (Rp '.number_format($chainPayment['amount'], 0, ',', '.').').';
            }

            $eventNames = array_column($decodedEvents, 'event');
            if ($eventNames && ! in_array($record->event_type, $eventNames, true)) {
                $tampered = true;
                $tamperReasons[] = "Tipe event di MySQL ({$record->event_type}) tidak cocok dengan log blockchain (".implode(', ', $eventNames).').';
            }
        }

        return view('verify', [
            'record' => $record,
            'txHash' => $txHash,
            'onChain' => $onChain,
            'decodedEvents' => $decodedEvents,
            'chainPayment' => $chainPayment,
            'tampered' => $tampered,
            'tamperReasons' => $tamperReasons,
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
