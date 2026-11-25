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
@elseif (empty($onChain))
  <div class="flash err" style="margin-top:14px">
    <b>VERIFIKASI GAGAL:</b> Transaction hash ini tidak ditemukan di blockchain Hardhat. Hash palsu atau node pernah di-reset.
  </div>
@elseif ($tampered)
  <div class="flash err" style="margin-top:14px">
    <b>⚠ MANIPULASI DATA TERDETEKSI!</b> Catatan MySQL tidak sesuai dengan bukti asli di Smart Contract:
    <ul style="margin:8px 0 0;padding-left:18px">
      @foreach ($tamperReasons as $reason)
        <li>{{ $reason }}</li>
      @endforeach
    </ul>
  </div>
@elseif (strtolower($onChain['to'] ?? '') !== strtolower($escrow))
  <div class="flash warn" style="margin-top:14px">
    <b>PERINGATAN:</b> Transaksi ada di blockchain, tetapi bukan ditujukan ke kontrak SafePayEscrow aktif.
  </div>
@elseif ($record)
  <div class="flash ok" style="margin-top:14px">
    <b>✓ TERVERIFIKASI:</b> Hash valid, transaksi sukses on-chain, dan catatan MySQL konsisten dengan Smart Contract SafePayEscrow.
  </div>
@else
  <div class="flash warn" style="margin-top:14px">
    <b>BUKTI ON-CHAIN UTUH:</b> Hash valid di blockchain, tetapi tidak ada di database MySQL (kemungkinan baris aplikasi dihapus).
  </div>
@endif

<div class="grid g2" style="margin-top:16px">
  <div class="card">
    <h2 style="margin-top:0">Catatan aplikasi (MySQL)</h2>
    @if ($record)
      <table>
        <tbody>
          <tr><th>Event</th><td>{{ $record->event_type }}</td></tr>
          <tr><th>Pesanan</th><td>{{ $record->escrow?->order?->product_name ?? '—' }}</td></tr>
          <tr><th>Nominal MySQL</th><td>{{ $record->escrow?->order ? 'Rp '.number_format($record->escrow->order->amount, 0, ',', '.') : '—' }}</td></tr>
          <tr><th>Escrow id</th><td>{{ $record->escrow?->contract_id ?? '—' }}</td></tr>
          <tr><th>Status MySQL</th><td><span class="tag {{ $tampered ? 's5' : '' }}">{{ $record->escrow?->status ?? '—' }}</span></td></tr>
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
    @if (! empty($onChain))
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
          <tr><th>Event Smart Contract</th><td>
            @if (! empty($decodedEvents))
              @foreach ($decodedEvents as $ev)
                <span class="tag s3">{{ $ev['event'] }} (escrow #{{ $ev['contract_id'] }})</span>
              @endforeach
            @else
              <span class="muted small">Tidak ada event SafePayEscrow</span>
            @endif
          </td></tr>
          @if ($chainPayment)
            <tr><th>Status Kontrak Saat Ini</th><td><span class="tag s3">{{ $chainPayment['status'] }}</span></td></tr>
            <tr><th>Nominal On-chain</th><td>{{ ($chainPayment['amount_eth'] ?? \App\Services\ChainReader::idrToEth($chainPayment['amount'])).' ETH' }} (Rp {{ number_format($chainPayment['amount'], 0, ',', '.') }})</td></tr>
          @endif
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
  Cara mendemokan manipulasi ke dosen: ubah kolom <code>status</code> di tabel <code>escrows</code> atau <code>amount</code> di tabel <code>orders</code> lewat HeidiSQL/phpMyAdmin, lalu muat ulang halaman ini.
  Sistem akan langsung mendeteksi manipulasi karena bukti asli di Smart Contract tidak bisa diubah.
</p>
@endsection
