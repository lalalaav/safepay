<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>SafePay — @yield('title', 'Blockchain Escrow')</title>
<link rel="stylesheet" href="/css/safepay.css">
<script>
window.SAFEPAY = {
  mode: "{{ config('safepay.mode') }}",
  escrow: "{{ config('safepay.escrow') }}",
  arbiter: "{{ config('safepay.arbiter') }}",
  weiPerIdr: "{{ config('safepay.wei_per_idr', '1000000000000') }}",
  userWallet: @json(auth()->user()?->address()),
  rpc: @json(config('safepay.browser_rpc')),
  rpcPort: {{ (int) config('safepay.rpc_port', 8545) }},
  chainId: {{ (int) config('safepay.chain_id', 31337) }},
  chainHex: "0x{{ dechex((int) config('safepay.chain_id', 31337)) }}",
  routes: {
    wallet: "{{ route('wallet.bind') }}",
    faucet: "{{ route('wallet.faucet') }}",
    tx: "{{ route('orders.tx', ['order' => '__ID__']) }}",
    live: "{{ route('live.state') }}"
  }
};
</script>
</head>
<body>
<nav>
  <span class="brand">SafePay</span>
  <span class="muted small">ETH Escrow · 1 ETH = Rp 1.000.000</span>
  @auth
    <a href="{{ route('dashboard') }}">Dashboard</a>
    <a href="{{ route('explorer') }}">Explorer</a>
    <span class="tag {{ config('safepay.mode') === 'manual' ? 's2' : 's1' }}">
      {{ config('safepay.mode') === 'manual' ? 'mode manual — tanpa MetaMask' : 'mode wallet — MetaMask (ETH)' }}
    </span>
    <span id="live-dot" class="tag">● Sync…</span>
    <span class="sp"></span>
    @if (config('safepay.mode') === 'wallet')
      <span class="muted small" data-wallet>wallet: belum tersambung</span>
      <button id="connect" type="button" class="btn-go btn-s">Connect MetaMask</button>
      <button id="open-mm-guide" type="button" class="btn-s" title="Panduan koneksi jaringan Hardhat di MetaMask">? Setup</button>
    @endif
    <span class="muted small">{{ auth()->user()->name }} · {{ auth()->user()->role }}</span>
    <form method="POST" action="{{ route('logout') }}" style="display:inline">@csrf<button class="btn-s">Keluar</button></form>
  @else
    <span class="sp"></span>
    <a href="{{ route('login') }}">Masuk</a>
    <a href="{{ route('register') }}">Daftar</a>
  @endauth
</nav>

<main>
  <div id="flash" class="flash" style="display:none"></div>
  @if (session('status'))
    <div class="flash ok">{{ session('status') }}</div>
  @endif
  @if (session('error'))
    <div class="flash err">{{ session('error') }}</div>
  @endif
  @if ($errors->any())
    <div class="flash err">
      @foreach ($errors->all() as $err)
        <div>{{ $err }}</div>
      @endforeach
    </div>
  @endif
  @yield('content')
</main>

<div id="live-toast" class="toast" style="display:none"></div>

@auth
@if (config('safepay.mode') === 'wallet')
<div id="mm-guide" class="modal-bg">
  <div class="modal">
    <div class="row" style="justify-content:space-between;margin-bottom:10px">
      <h2 style="margin:0">Panduan Koneksi MetaMask (ETH Hardhat Local)</h2>
      <button id="close-mm-guide" type="button" class="btn-s">Tutup</button>
    </div>
    <p class="small muted">
      SafePay menggunakan <b>ETH simulasi bawaan Hardhat</b> secara langsung (kurs simulasi tetap: <b>1 ETH = Rp 1.000.000</b>).
      Jika MetaMask perlu ditambahkan manual, buka <b>MetaMask → Settings → Networks → Add a custom network</b>:
    </p>
    <table>
      <tbody>
        <tr><th>Network Name</th><td class="mono">SafePay Hardhat Local</td></tr>
        <tr>
          <th>RPC URL</th>
          <td>
            <span class="mono" data-guide-rpc>{{ config('safepay.browser_rpc') ?: 'http://127.0.0.1:8545' }}</span>
            <button type="button" class="btn-s" data-copy="rpc" style="margin-left:6px">Salin</button>
          </td>
        </tr>
        <tr><th>Chain ID</th><td class="mono">{{ (int) config('safepay.chain_id', 31337) }}</td></tr>
        <tr><th>Currency Symbol</th><td class="mono">ETH</td></tr>
        <tr><th>Kontrak SafePayEscrow</th><td class="mono">{{ config('safepay.escrow') }}</td></tr>
        @if (auth()->user()->address())
        <tr><th>Wallet Terdaftar Akun Ini</th><td class="mono">{{ auth()->user()->address() }}</td></tr>
        @endif
      </tbody>
    </table>
    <p class="small muted" style="margin-top:12px">
      Tips cepat:<br>
      1. Klik tombol <b>+1 ETH Demo</b> di kanan atas Dashboard untuk mengisi saldo ETH simulasi tanpa perlu kirim dari akun lain.<br>
      2. Pastikan akun yang aktif di MetaMask sesuai dengan wallet terdaftar pengguna yang sedang login.<br>
      3. Jika pernah merestart Hardhat node, bersihkan nonce lama di <b>MetaMask → Settings → Advanced → Clear activity tab data</b>.
    </p>
  </div>
</div>
@endif
@endauth

<script src="/vendor/ethers.umd.min.js"></script>
@auth
<script src="/js/safepay.js?v=eth3"></script>
@endauth
</body>
</html>
