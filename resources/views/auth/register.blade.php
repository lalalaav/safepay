@extends('layouts.app')
@section('title', 'Daftar Akun')

@section('content')
<h1>Daftar Akun SafePay</h1>
<p class="muted small">Buat akun baru dengan peran pembeli, penjual, atau auditor. Wallet MetaMask bisa disambungkan setelah masuk.</p>

<div class="grid g2" style="margin-top:18px">
  <form method="POST" action="{{ route('register') }}" class="card">
    @csrf
    <label>Nama lengkap
      <input name="name" value="{{ old('name') }}" required autofocus placeholder="Contoh: Rina">
    </label>
    <label>Email
      <input name="email" type="email" value="{{ old('email') }}" required placeholder="rina@safepay.test">
    </label>
    <label>Peran
      <select name="role" required>
        <option value="buyer" @selected(old('role') === 'buyer')>Pembeli (buyer)</option>
        <option value="seller" @selected(old('role') === 'seller')>Penjual (seller)</option>
        <option value="auditor" @selected(old('role') === 'auditor')>Auditor / Penguji (auditor)</option>
      </select>
    </label>
    <label>Alamat Wallet Ethereum (opsional — bisa dihubungkan otomatis via MetaMask nanti)
      <input name="wallet_address" value="{{ old('wallet_address') }}" placeholder="0x...">
    </label>
    <label>Password
      <input name="password" type="password" required minlength="6">
    </label>
    <label>Konfirmasi Password
      <input name="password_confirmation" type="password" required minlength="6">
    </label>
    <button class="btn-go" style="width:100%">Daftar &amp; Masuk</button>
    <p class="muted small" style="margin-top:12px;margin-bottom:0">
      Sudah punya akun? <a href="{{ route('login') }}">Masuk di sini</a>
    </p>
  </form>

  <div class="card">
    <h2 style="margin-top:0">Catatan Peran</h2>
    <ul class="small muted" style="padding-left:18px;line-height:1.7">
      <li><b>Buyer (Pembeli)</b>: membuat pesanan, membayar ke smart contract escrow, mengonfirmasi barang diterima, refund, atau mengajukan sengketa.</li>
      <li><b>Seller (Penjual)</b>: melihat pesanan masuk &amp; dana tertahan, menandai pengiriman, menerima pelepasan dana, atau mengajukan sengketa.</li>
      <li><b>Auditor / Penguji</b>: memantau seluruh transaksi, sengketa, dan memverifikasi keaslian hash di Blockchain Explorer (baca-saja).</li>
      <li><b>Validator (Arbiter)</b>: ditetapkan pada smart contract saat deployment (akun demo Citra) untuk memutus sengketa secara transparan.</li>
    </ul>
  </div>
</div>
@endsection
