<?php

// Alamat kontrak dibaca dari blockchain/deployments.json (hasil scripts/deploy.ts).
// .env tetap menang kalau diisi, supaya bisa arahkan ke node lain tanpa edit file.
$deploy = json_decode(@file_get_contents(base_path('blockchain/deployments.json')) ?: '', true) ?: [];

return [
    'rpc' => env('SAFEPAY_RPC', $deploy['rpc'] ?? 'http://127.0.0.1:8545'),
    // RPC yang disuntik ke browser MetaMask. Backend tetap memakai localhost;
    // browser peserta memakai IP LAN agar 127.0.0.1 tidak menunjuk ke perangkatnya sendiri.
    'browser_rpc' => env('SAFEPAY_BROWSER_RPC', env('SAFEPAY_RPC', $deploy['rpc'] ?? 'http://127.0.0.1:8545')),
    'chain_id' => (int) env('SAFEPAY_CHAIN_ID', $deploy['chainId'] ?? 31337),
    // 'manual' = server tanda tangan pakai akun Hardhat (tanpa MetaMask).
    // 'wallet' = pengguna tanda tangan sendiri lewat MetaMask di browser.
    'mode' => env('SAFEPAY_MODE', 'manual'),
    'token' => env('SAFEPAY_TOKEN', $deploy['token'] ?? ''),
    'escrow' => env('SAFEPAY_ESCROW', $deploy['escrow'] ?? ''),
    'arbiter' => env('SAFEPAY_ARBITER', $deploy['arbiter'] ?? ''),
];
