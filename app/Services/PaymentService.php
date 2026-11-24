<?php

namespace App\Services;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Mode DEMO MANUAL: menjalankan aksi escrow dari server, tanpa MetaMask.
 *
 * Untuk apa begini? MetaMask butuh ekstensi browser di tiap perangkat dan
 * menolak RPC http:// non-HTTPS (jadi HP tidak bisa). Untuk presentasi kelas,
 * tanda tangan dilakukan akun Hardhat deterministik yang ada di server.
 *
 * Peringatan: private key akun Hardhat berada di file script ini dan hanya
 * berlaku di jaringan simulasi lokal. JANGAN pakai pola ini di jaringan
 * sungguhan — di sana setiap pengguna harus menandatangani sendiri lewat
 * wallet, dan server tidak boleh pernah memegang private key.
 */
class PaymentService
{
    private const ACTIONS = ['pay', 'ship', 'confirm', 'refund', 'dispute', 'resolve'];

    public function run(string $action, array $params = []): array
    {
        if (! in_array($action, self::ACTIONS, true)) {
            throw new RuntimeException("Aksi tidak dikenal: {$action}");
        }

        // PHP/Laragon kadang tidak mewarisi PATH Node.js. Gunakan path absolut
        // jika instalasi standar tersedia, lalu panggil batch file dengan `call`.
        $npx = 'C:\\Program Files\\nodejs\\npx.cmd';
        if (! is_file($npx)) {
            $npx = 'npx.cmd';
        }

        $process = new Process(
            ['cmd.exe', '/d', '/s', '/c', 'call', $npx, 'hardhat', 'run', 'scripts/actions.ts', '--network', 'localhost'],
            base_path('blockchain'),
            ['SAFEPAY_SPEC' => json_encode(['action' => $action] + $params, JSON_THROW_ON_ERROR)],
        );
        $process->setTimeout(300);
        $process->run();

        $result = $this->lastJson($process->getOutput());

        if ($result === null) {
            $detail = trim($process->getErrorOutput()."\n".$process->getOutput());

            throw new RuntimeException($detail !== '' ? $detail : 'Hardhat tidak mengembalikan hasil.');
        }

        return $result;
    }

    /** Hardhat mencetak banyak baris; ambil baris JSON terakhir yang memuat tx_hash. */
    private function lastJson(string $output): ?array
    {
        foreach (array_reverse(preg_split('/\R/', $output)) as $line) {
            $line = trim($line);

            if (! str_starts_with($line, '{')) {
                continue;
            }

            $decoded = json_decode($line, true);

            if (is_array($decoded) && isset($decoded['tx_hash'])) {
                return $decoded;
            }
        }

        return null;
    }
}
