<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Baca-saja ke smart contract lewat JSON-RPC mentah.
 * Sengaja tanpa library web3 PHP: yang dibutuhkan cuma eth_call dan
 * eth_getTransactionReceipt, dan server tidak pernah pegang private key —
 * semua transaksi tulis ditandatangani MetaMask di browser.
 */
class ChainReader
{
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

    /** Panggilan view. Default ke escrow; `to` untuk kontrak lain (token). */
    public function call(string $data, ?string $to = null): string
    {
        return (string) $this->rpc('eth_call', [
            ['to' => $to ?? config('safepay.escrow'), 'data' => $data],
            'latest',
        ]);
    }

    /** payments(uint256) -> buyer, seller, amount, status, deadline */
    public function payment(int $id): array
    {
        $raw = substr($this->call('0x87d81789'.str_pad(dechex($id), 64, '0', STR_PAD_LEFT)), 2);
        $word = fn (int $i) => substr($raw, $i * 64, 64);

        return [
            'buyer' => '0x'.substr($word(0), 24),
            'seller' => '0x'.substr($word(1), 24),
            'amount' => hexdec($word(2)),
            'status' => self::STATUS[hexdec($word(3))] ?? 'UNKNOWN',
            'deadline' => hexdec($word(4)),
        ];
    }

    /** Dana yang benar-benar ditahan kontrak, bukan angka dari MySQL. */
    public function escrowBalance(): int
    {
        return $this->tokenBalance(config('safepay.escrow'));
    }

    /** Saldo mIDR milik sebuah wallet. `to` = kontrak token, argumen = pemegang saldo. */
    public function tokenBalance(string $holder): int
    {
        $data = '0x70a08231'.str_pad(substr($holder, 2), 64, '0', STR_PAD_LEFT);
        // ponytail: hexdec pakai float kalau > PHP_INT_MAX. Nominal demo aman.
        // Upgrade ke bcmath kalau saldo token bisa lebih dari 9e18.
        return (int) hexdec($this->call($data, config('safepay.token')));
    }

    public function receipt(string $txHash): array
    {
        return (array) $this->rpc('eth_getTransactionReceipt', [$txHash]);
    }

    /**
     * Verifikasi anti-manipulasi: hash harus ada, sukses, tujuan kontrak escrow,
     * dan memuat minimal satu event yang diharapkan. Ubah catatan di MySQL,
     * fungsi ini tetap gagal — itu yang ditunjukkan ke dosen.
     *
     * $expectedId mengunci event ke escrow id pesanan yang bersangkutan:
     * hash sah milik pesanan lain tidak bisa ditempelkan ke pesanan ini.
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

    /**
     * Id pembayaran (PaymentCreated) dari sebuah hash. Null kalau receipt tidak
     * sukses, bukan dari kontrak escrow, atau tidak memuat event itu.
     * Dipakai supaya contract_id tidak pernah diambil dari input pengguna.
     */
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
