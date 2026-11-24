@extends('layouts.app')
@section('title', 'Masuk')

@section('content')
<h1>Masuk SafePay</h1>
<p class="muted small">Escrow pembayaran digital di atas blockchain lokal Hardhat.</p>

<div class="grid g2" style="margin-top:18px">
  <form method="POST" action="{{ route('login') }}" class="card">
    @csrf
    <label>Email
      <input name="email" type="email" value="{{ old('email') }}" required autofocus>
    </label>
    <label>Password
      <input name="password" type="password" required>
    </label>
    <button class="btn-go" style="width:100%">Masuk</button>
  </form>

  <div class="card">
    <h2 style="margin-top:0">Akun demo</h2>
    <p class="muted small">Password semua akun: <code>password</code>. Wallet sudah dipetakan ke akun Hardhat 0–3.</p>
    <table>
      <tbody>
        <tr><td>Andi</td><td class="mono">andi@safepay.test</td><td class="muted small">pembeli</td></tr>
        <tr><td>Budi</td><td class="mono">budi@safepay.test</td><td class="muted small">penjual</td></tr>
        <tr><td>Citra</td><td class="mono">citra@safepay.test</td><td class="muted small">validator</td></tr>
        <tr><td>Dinda</td><td class="mono">dinda@safepay.test</td><td class="muted small">auditor</td></tr>
      </tbody>
    </table>
  </div>
</div>
@endsection
