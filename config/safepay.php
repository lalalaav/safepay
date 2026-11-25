<?php

// Alamat kontrak dibaca dari blockchain/deployments.json (hasil scripts/deploy.ts).
// .env tetap menang kalau diisi, supaya bisa arahkan ke node lain tanpa edit file.
$deploy = json_decode(@file_get_contents(base_path('blockchain/deployments.json')) ?: '', true) ?: [];

return [
    'rpc' => env('SAFEPAY_RPC', $deploy['rpc'] ?? 'http://127.0.0.1:8545'),
    // Override opsional untuk browser MetaMask (mis. tunnel HTTPS).
    // Jika kosong, safepay.js otomatis memilih http://127.0.0.1:8545 (lokal) atau http://<host-LAN>:8545.
    'browser_rpc' => env('SAFEPAY_BROWSER_RPC') ?: null,
    'rpc_port' => (int) env('SAFEPAY_RPC_PORT', 8545),
    'chain_id' => (int) env('SAFEPAY_CHAIN_ID', $deploy['chainId'] ?? 31337),
    // 'manual' = server tanda tangan pakai akun Hardhat (tanpa MetaMask).
    // 'wallet' = pengguna tanda tangan sendiri lewat MetaMask di browser.
    'mode' => env('SAFEPAY_MODE', 'wallet'),
    // Kurs simulasi: 1 ETH = Rp 1.000.000 (1 Rupiah = 10^12 wei).
    'wei_per_idr' => env('SAFEPAY_WEI_PER_IDR', $deploy['weiPerIdr'] ?? '1000000000000'),
    'escrow' => env('SAFEPAY_ESCROW', $deploy['escrow'] ?? ''),
    'arbiter' => env('SAFEPAY_ARBITER', $deploy['arbiter'] ?? ''),
    'faucet' => env('SAFEPAY_FAUCET', $deploy['faucet'] ?? '0xa0Ee7A142d267C1f36714E4a8F75612F20a79720'),
];
