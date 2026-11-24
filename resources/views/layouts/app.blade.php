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
  escrow: "{{ config('safepay.escrow') }}",
  token: "{{ config('safepay.token') }}",
  rpc: "{{ config('safepay.rpc') }}",
  chainHex: "0x{{ dechex((int) config('safepay.chain_id')) }}",
  routes: {
    wallet: "{{ route('wallet.bind') }}",
    tx: "{{ route('orders.tx', ['order' => '__ID__']) }}"
  }
};
</script>
</head>
<body>
<nav>
  <span class="brand">SafePay</span>
  <span class="muted small">Blockchain Escrow Payment System</span>
  @auth
    <a href="{{ route('dashboard') }}">Dashboard</a>
    <a href="{{ route('explorer') }}">Explorer</a>
    <span class="tag {{ config('safepay.mode') === 'manual' ? 's2' : 's1' }}">
      {{ config('safepay.mode') === 'manual' ? 'mode manual — tanpa MetaMask' : 'mode wallet — MetaMask' }}
    </span>
    <span class="sp"></span>
    @if (config('safepay.mode') === 'wallet')
      <span class="muted small" data-wallet>wallet: belum tersambung</span>
      <button id="connect" class="btn-s">Connect MetaMask</button>
    @endif
    <span class="muted small">{{ auth()->user()->name }} · {{ auth()->user()->role }}</span>
    <form method="POST" action="{{ route('logout') }}">@csrf<button class="btn-s">Keluar</button></form>
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
  @yield('content')
</main>

<script src="/vendor/ethers.umd.min.js"></script>
@if (config('safepay.mode') === 'wallet')
<script src="/js/safepay.js"></script>
@endif
</body>
</html>
