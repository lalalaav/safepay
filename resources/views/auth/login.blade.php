@extends('layouts.app')
@section('title', 'Masuk')

@section('content')
<h1>Masuk SafePay</h1>
<p class="muted small">Escrow pembayaran digital berbasis Smart Contract di atas blockchain lokal Hardhat.</p>

<div class="grid g2" style="margin-top:18px">
  <form method="POST" action="{{ route('login') }}" class="card">
    @csrf
    <label>Email
      <input name="email" type="email" value="{{ old('email') }}" required autofocus placeholder="andi@safepay.test">
    </label>
    <label>Password
      <input name="password" type="password" required placeholder="password">
    </label>
    <button class="btn-go" style="width:100%">Masuk</button>
    <p class="muted small" style="margin-top:12px;margin-bottom:0">
      Belum punya akun? <a href="{{ route('register') }}">Daftar akun baru</a>
    </p>
  </form>

  <div class="card">
    <h2 style="margin-top:0">Akun demo (4–5 device)</h2>
    <p class="muted small">Password semua akun: <code>password</code>. Wallet dipetakan ke akun Hardhat #0–#4.</p>
    <div class="table-wrap">
      <table>
        <thead>
          <tr><th>Nama</th><th>Email</th><th>Peran</th><th>Hardhat</th></tr>
        </thead>
        <tbody>
          <tr><td>Andi</td><td class="mono">andi@safepay.test</td><td class="muted small">buyer (pembeli)</td><td class="mono small">#0</td></tr>
          <tr><td>Budi</td><td class="mono">budi@safepay.test</td><td class="muted small">seller (penjual)</td><td class="mono small">#1</td></tr>
          <tr><td>Citra</td><td class="mono">citra@safepay.test</td><td class="muted small">validator (arbiter)</td><td class="mono small">#2</td></tr>
          <tr><td>Dinda</td><td class="mono">dinda@safepay.test</td><td class="muted small">auditor (pengamat)</td><td class="mono small">#3</td></tr>
          <tr><td>Eko</td><td class="mono">eko@safepay.test</td><td class="muted small">auditor (penguji)</td><td class="mono small">#4</td></tr>
        </tbody>
      </table>
    </div>
  </div>
</div>
@endsection
