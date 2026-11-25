<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Akses JSON-RPC ke node Hardhat untuk membaca status & saldo ETH Smart Contract.
 * Kurs simulasi: 1 ETH = Rp 1.000.000 (1 Rupiah = 10^12 wei).
 */
class ChainReader
{
    public const WEI_PER_ETH = '1000000000000000000';

    public const DEFAULT_WEI_PER_IDR = '1000000000000';

    private const STATUS = ['CREATED', 'FUNDED', 'SHIPPED', 'RELEASED', 'REFUNDED', 'DISPUTED'];

    public function rpc(string $method, array $params = []): mixed
    {
        $res = Http::timeout(10)->post(config('safepay.rpc'), [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => $method,
            'params' => $params,
        ]);

        if ($error = $res->json('error')) {
            throw new RuntimeException('RPC: '.json_encode($error));
        }

        return $res->json('result');
    }

    /** Panggilan view ke smart contract escrow. */
    public function call(string $data, ?string $to = null): string
    {
        return (string) $this->rpc('eth_call', [
            ['to' => $to ?? config('safepay.escrow'), 'data' => $data],
            'latest',
        ]);
    }

    /** Nomor block terbaru dari node blockchain. */
    public function blockNumber(): int
    {
        $hex = (string) $this->rpc('eth_blockNumber');

        return (int) hexdec($hex);
    }

    /** Pastikan bytecode kontrak benar-benar ter-deploy di node saat ini. */
    public function hasCode(?string $address): bool
    {
        if (! $address) {
            return false;
        }

        $code = (string) $this->rpc('eth_getCode', [$address, 'latest']);

        return $code !== '' && $code !== '0x';
    }

    /** Status kesehatan node & deployment kontrak SafePayEscrow. */
    public function health(): array
    {
        try {
            $block = $this->blockNumber();
            $escrowOk = $this->hasCode(config('safepay.escrow'));

            return [
                'node_ok' => true,
                'deployed' => $escrowOk,
                'block_number' => $block,
                'error' => $escrowOk ? null : 'Kontrak SafePayEscrow belum ter-deploy di node Hardhat saat ini. Jalankan: npm run deploy di folder blockchain.',
            ];
        } catch (\Throwable) {
            return [
                'node_ok' => false,
                'deployed' => false,
                'block_number' => null,
                'error' => 'Node Hardhat belum aktif. Jalankan: npm run node di folder blockchain.',
            ];
        }
    }

    /** Konversi hex 256-bit ke string desimal akurat memakai bcmath (aman untuk >= 10.000 ETH). */
    public static function hexToDec(string $hex): string
    {
        $clean = preg_replace('/^0x/i', '', trim($hex));
        if ($clean === '' || $clean === null) {
            return '0';
        }

        $dec = '0';
        $len = strlen($clean);
        for ($i = 0; $i < $len; $i++) {
            $dec = bcadd(bcmul($dec, '16', 0), (string) hexdec($clean[$i]), 0);
        }

        return $dec;
    }

    public static function weiPerIdr(): string
    {
        return (string) (config('safepay.wei_per_idr') ?: self::DEFAULT_WEI_PER_IDR);
    }

    public static function weiToIdr(string $wei): int
    {
        return (int) bcdiv($wei, self::weiPerIdr(), 0);
    }

    public static function weiToEth(string $wei, int $decimals = 4): string
    {
        $str = bcdiv($wei, self::WEI_PER_ETH, $decimals);

        return rtrim(rtrim($str, '0'), '.') ?: '0';
    }

    public static function idrToEth(int|string $idr, int $decimals = 4): string
    {
        $wei = bcmul((string) $idr, self::weiPerIdr(), 0);

        return self::weiToEth($wei, $decimals);
    }

    /** payments(uint256) -> buyer, seller, amount (Rp), amount_eth, status, deadline */
    public function payment(int $id): ?array
    {
        $result = $this->call('0x87d81789'.str_pad(dechex($id), 64, '0', STR_PAD_LEFT));
        $raw = str_starts_with($result, '0x') ? substr($result, 2) : $result;

        if (strlen($raw) < 320) {
            throw new RuntimeException('Kontrak SafePayEscrow tidak merespons (kemungkinan belum ter-deploy di node saat ini).');
        }

        $word = fn (int $i) => substr($raw, $i * 64, 64);
        $buyer = '0x'.substr($word(0), 24);

        if (strtolower($buyer) === '0x0000000000000000000000000000000000000000') {
            return null;
        }

        $wei = self::hexToDec($word(2));

        return [
            'buyer' => $buyer,
            'seller' => '0x'.substr($word(1), 24),
            'amount' => self::weiToIdr($wei),
            'amount_wei' => $wei,
            'amount_eth' => self::weiToEth($wei, 4),
            'status' => self::STATUS[(int) hexdec($word(3))] ?? 'UNKNOWN',
            'deadline' => (int) hexdec($word(4)),
        ];
    }

    /** Saldo wei asli dari sebuah alamat di blockchain lokal. */
    public function balanceWei(string $holder): string
    {
        $hex = (string) $this->rpc('eth_getBalance', [$holder, 'latest']);

        return self::hexToDec($hex);
    }

    /** Saldo ETH terformat (mis. "1.0000" atau "0.85") milik alamat tertentu. */
    public function ethBalance(string $holder, int $decimals = 4): string
    {
        return self::weiToEth($this->balanceWei($holder), $decimals);
    }

    /** Dana ETH yang benar-benar tertahan di alamat smart contract escrow (dalam ekuivalen Rp). */
    public function escrowBalance(): int
    {
        return $this->tokenBalance((string) config('safepay.escrow'));
    }

    /** Ekuivalen Rupiah dari saldo ETH sebuah alamat (1 ETH = Rp 1.000.000). */
    public function tokenBalance(string $holder): int
    {
        return self::weiToIdr($this->balanceWei($holder));
    }

    /**
     * Faucet simulasi lokal: kirim ETH dari akun cadangan Hardhat (#9) ke wallet pengguna.
     * Bekerja instan bahkan saat saldo ETH wallet tujuan masih 0 ETH.
     */
    public function faucetEth(string $toAddress, int $ethAmount = 1): string
    {
        $from = (string) config('safepay.faucet', '0xa0Ee7A142d267C1f36714E4a8F75612F20a79720');
        // 1 ETH = 0xde0b6b3a7640000 wei
        $weiDec = bcmul((string) $ethAmount, self::WEI_PER_ETH, 0);
        $weiHex = '0x'.self::decToHex($weiDec);

        return (string) $this->rpc('eth_sendTransaction', [[
            'from' => $from,
            'to' => $toAddress,
            'value' => $weiHex,
        ]]);
    }

    private static function decToHex(string $dec): string
    {
        if (bccomp($dec, '0', 0) <= 0) {
            return '0';
        }

        $hex = '';
        $digits = '0123456789abcdef';
        while (bccomp($dec, '0', 0) > 0) {
            $rem = (int) bcmod($dec, '16');
            $hex = $digits[$rem].$hex;
            $dec = bcdiv($dec, '16', 0);
        }

        return $hex;
    }

    public function receipt(string $txHash): array
    {
        return (array) $this->rpc('eth_getTransactionReceipt', [$txHash]);
    }

    /** Dekode event SafePayEscrow yang ada di dalam receipt untuk halaman verifikasi. */
    public function decodeEscrowEvents(array $receipt): array
    {
        $found = [];
        $topicMap = array_flip(array_map('strtolower', self::TOPIC));

        foreach ($receipt['logs'] ?? [] as $log) {
            if (strtolower($log['address'] ?? '') !== strtolower((string) config('safepay.escrow'))) {
                continue;
            }

            $topic0 = strtolower($log['topics'][0] ?? '');

            if (isset($topicMap[$topic0])) {
                $found[] = [
                    'event' => $topicMap[$topic0],
                    'contract_id' => $this->eventId($log),
                ];
            }
        }

        return $found;
    }

    /**
     * Verifikasi anti-manipulasi: hash harus ada, sukses, tujuan kontrak escrow,
     * dan memuat minimal satu event yang diharapkan.
     */
    public function verify(string $txHash, array $topics, ?int $expectedId = null): bool
    {
        $receipt = $this->receipt($txHash);

        if (! $this->isEscrowReceipt($receipt)) {
            return false;
        }

        foreach ($receipt['logs'] ?? [] as $log) {
            $topic0 = strtolower($log['topics'][0] ?? '');

            foreach ($topics as $topic) {
                if ($topic0 !== strtolower($topic)) {
                    continue;
                }

                if ($expectedId !== null && $this->eventId($log) !== $expectedId) {
                    continue;
                }

                return true;
            }
        }

        return false;
    }

    /** Id pembayaran (PaymentCreated) dari sebuah hash. */
    public function paymentIdFromReceipt(string $txHash): ?int
    {
        $receipt = $this->receipt($txHash);

        if (! $this->isEscrowReceipt($receipt)) {
            return null;
        }

        foreach ($receipt['logs'] ?? [] as $log) {
            if (strtolower($log['topics'][0] ?? '') === strtolower(self::TOPIC['PaymentCreated'])) {
                return $this->eventId($log);
            }
        }

        return null;
    }

    /** Receipt sukses dan memang dihasilkan kontrak escrow yang aktif. */
    private function isEscrowReceipt(array $receipt): bool
    {
        if (($receipt['status'] ?? '0x0') !== '0x1') {
            return false;
        }

        return strtolower($receipt['to'] ?? '') === strtolower((string) config('safepay.escrow'));
    }

    /** Semua event kontrak ini meng-index id escrow di topics[1]. */
    private function eventId(array $log): ?int
    {
        $topic = $log['topics'][1] ?? null;

        return $topic ? (int) hexdec(substr($topic, 2)) : null;
    }

    /** Topic0 tiap event kontrak, buat kolom event_type di blockchain_transactions. */
    public const TOPIC = [
        'PaymentCreated' => '0xbca8aeacf1f38658b18930496eecdc82575bb54ced59ae5ddea3c2a221bc19aa',
        'Shipped' => '0xf00b9a5725b80c54016f42114f6ed7792d22eecb116011c4afac29c57bef6c95',
        'Released' => '0xd5840651550c173a8bf246eede440a59253bc61eadb2e8887168faad2fad631d',
        'Refunded' => '0xb7c1f9298a18c36af08bd57eabbfbeb04695f24d0438f67614741ec15fb5c4a9',
        'Disputed' => '0xd204349004c12248e5f9be3960833f6fded8178a82a06dab9f9a5c660d705173',
        'DisputeResolved' => '0x5a87909bff68caaaaf0b3fd9c74eeccc928832f879315e5c6fb7a73612f26c0c',
    ];
}
