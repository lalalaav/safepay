@extends('layouts.app')
@section('title', 'Verifikasi transaksi')

@section('content')
<h1>Verifikasi transaksi</h1>
<p class="muted small">Membandingkan catatan di MySQL dengan bukti asli di blockchain.</p>

<div class="card" style="margin-top:16px">
  <div class="muted small">Transaction hash</div>
  <div class="mono">{{ $txHash }}</div>
</div>

@if ($error)
  <div class="flash err" style="margin-top:14px">Node blockchain tidak bisa dihubungi: {{ $error }}</div>
@endif

<div class="grid g2" style="margin-top:16px">
  <div class="card">
    <h2 style="margin-top:0">Catatan aplikasi (MySQL)</h2>
    @if ($record)
      <table>
        <tbody>
          <tr><th>Event</th><td>{{ $record->event_type }}</td></tr>
          <tr><th>Pesanan</th><td>{{ $record->escrow?->order?->product_name ?? '—' }}</td></tr>
          <tr><th>Escrow id</th><td>{{ $record->escrow?->contract_id ?? '—' }}</td></tr>
          <tr><th>Status MySQL</th><td>{{ $record->escrow?->status ?? '—' }}</td></tr>
          <tr><th>Block</th><td>{{ $record->block_number ?? '—' }}</td></tr>
          <tr><th>Dicatat</th><td>{{ $record->created_at }}</td></tr>
        </tbody>
      </table>
    @else
      <p class="muted">Hash ini tidak ada di database aplikasi. Kalau di blockchain ada, berarti bukti asli tetap utuh walau baris MySQL dihapus.</p>
    @endif
  </div>

  <div class="card">
    <h2 style="margin-top:0">Bukti blockchain (Hardhat)</h2>
    @if ($onChain)
      <table>
        <tbody>
          <tr><th>Status eksekusi</th><td>
            <span class="tag {{ ($onChain['status'] ?? '') === '0x1' ? 's3' : 's5' }}">
              {{ ($onChain['status'] ?? '') === '0x1' ? 'SUKSES (0x1)' : 'GAGAL' }}
            </span>
          </td></tr>
          <tr><th>Block</th><td>{{ isset($onChain['blockNumber']) ? hexdec($onChain['blockNumber']) : '—' }}</td></tr>
          <tr><th>Dari</th><td class="mono">{{ $onChain['from'] ?? '—' }}</td></tr>
          <tr><th>Ke</th><td class="mono">{{ $onChain['to'] ?? '—' }}</td></tr>
          <tr><th>Gas terpakai</th><td>{{ isset($onChain['gasUsed']) ? hexdec($onChain['gasUsed']) : '—' }}</td></tr>
          <tr><th>Jumlah log</th><td>{{ count($onChain['logs'] ?? []) }}</td></tr>
        </tbody>
      </table>
      <p class="small {{ strtolower($onChain['to'] ?? '') === strtolower($escrow) ? '' : 'muted' }}"
         style="margin-top:10px">
        {{ strtolower($onChain['to'] ?? '') === strtolower($escrow)
            ? 'Transaksi benar ditujukan ke kontrak escrow SafePay.'
            : 'Transaksi ini bukan ke kontrak escrow SafePay.' }}
      </p>
    @else
      <p class="muted">Tidak ditemukan di blockchain.</p>
    @endif
  </div>
</div>

<p class="muted small" style="margin-top:16px">
  Cara mendemokan manipulasi: ubah kolom status di tabel <code>escrows</code> lewat HeidiSQL, lalu muat ulang halaman ini.
  Catatan MySQL berubah, bukti blockchain tetap sama — dan dashboard tetap menampilkan status dari smart contract.
</p>
@endsection
