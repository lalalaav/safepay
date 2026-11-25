@extends('layouts.app')
@section('title', 'Blockchain Explorer')

@section('content')
@php
  $fmtEthRp = fn ($rp) => \App\Services\ChainReader::idrToEth($rp).' ETH';
@endphp
<h1>Blockchain Explorer</h1>
<p class="muted small">
  Halaman ini <b>baca-saja</b>: hanya menampilkan bukti dari blockchain. Transaksi dibuat dari
  <a href="{{ route('dashboard') }}">Dashboard</a>.
</p>

@if (! ($health['node_ok'] ?? false) || ! ($health['deployed'] ?? false))
  <div class="flash warn" style="margin-top:14px">
    <b>Status Blockchain:</b> {{ $health['error'] ?? 'Node blockchain belum siap.' }}
  </div>
@endif

<div class="grid g4" style="margin-top:14px">
  <div class="kpi">
    <span class="muted small">ETH tertahan di kontrak</span>
    <b data-bal="escrow">{{ $chainBalance === null ? 'node mati' : $fmtEthRp($chainBalance) }}</b>
    <div class="muted small" data-bal="escrow-rp">{{ $chainBalance === null ? '' : '≈ Rp '.number_format($chainBalance, 0, ',', '.') }}</div>
  </div>
  <div class="kpi"><span class="muted small">Block terbaru</span><b class="mono">{{ ($health['node_ok'] ?? false) ? '#'.$health['block_number'] : 'n/a' }}</b></div>
  <div class="kpi"><span class="muted small">Total escrow</span><b>{{ $escrows->count() }}</b></div>
  <div class="kpi"><span class="muted small">Total tx tercatat</span><b>{{ $txs->count() }}</b></div>
</div>

<p class="muted small" style="margin-top:12px">
  Kontrak escrow: <span class="mono">{{ config('safepay.escrow') }}</span>
  · Arbiter: <span class="mono">{{ config('safepay.arbiter') }}</span>
  · Kurs simulasi: <b>1 ETH = Rp 1.000.000</b>
</p>

<div class="grid g2" style="margin-top:14px">
  <form method="GET" action="{{ route('explorer') }}" class="card bar">
    <label>Filter riwayat hash (MySQL)
      <input name="tx" value="{{ $query }}" placeholder="Sebagian atau penuh 0x…">
    </label>
    <div style="flex:0 0 auto"><button class="btn-s">Filter</button></div>
  </form>

  <form method="GET" action="{{ route('explorer') }}" class="card bar">
    <label>Verifikasi hash langsung ke blockchain (penguji)
      <input name="verify_tx" placeholder="Tempel 0x... (64 hex) untuk uji keaslian">
    </label>
    <div style="flex:0 0 auto"><button class="btn-go">Verifikasi</button></div>
  </form>
</div>

<h2>Status escrow di smart contract</h2>
<div class="table-wrap">
  <table>
    <thead><tr><th>Escrow id</th><th>Pesanan</th><th>Pembeli (on-chain)</th><th>Penjual (on-chain)</th><th>Nominal (ETH / Rp)</th><th>MySQL vs On-chain</th><th>Deadline</th></tr></thead>
    <tbody>
      @forelse ($escrows as $escrow)
        @php
          $c = $escrow->getAttribute('chain');
          $tone = $c ? (['CREATED' => 's1', 'FUNDED' => 's1', 'SHIPPED' => 's2', 'RELEASED' => 's3', 'REFUNDED' => 's4', 'DISPUTED' => 's5'][$c['status']] ?? 's1') : '';
          $mismatch = $c && ($escrow->status !== $c['status'] || ($escrow->order && (int) $escrow->order->amount !== (int) $c['amount']));
        @endphp
        <tr>
          <td>{{ $escrow->contract_id }}</td>
          <td>{{ $escrow->order?->product_name ?? '—' }}</td>
          @if ($c)
            <td class="mono">{{ $c['buyer'] }}</td>
            <td class="mono">{{ $c['seller'] }}</td>
            <td>
              <b>{{ ($c['amount_eth'] ?? \App\Services\ChainReader::idrToEth($c['amount'])).' ETH' }}</b>
              <div class="small muted">Rp {{ number_format($c['amount'], 0, ',', '.') }}</div>
            </td>
            <td>
              <span class="tag {{ $tone }}">{{ $c['status'] }}</span>
              @if ($mismatch)
                <div class="small" style="color:var(--bad);margin-top:4px">⚠ MySQL: {{ $escrow->status }} (dimanipulasi!)</div>
              @endif
            </td>
            <td class="small muted">{{ date('d M Y H:i', $c['deadline']) }}</td>
          @else
            <td colspan="5" class="muted small">node blockchain tidak bisa dibaca / kontrak belum di-deploy</td>
          @endif
        </tr>
      @empty
        <tr><td colspan="7" class="muted">Belum ada escrow.</td></tr>
      @endforelse
    </tbody>
  </table>
</div>

<h2>Riwayat transaksi tercatat</h2>
<div class="table-wrap">
  <table>
    <thead><tr><th>Waktu</th><th>Event</th><th>Pesanan</th><th>Block</th><th>Transaction hash</th><th></th></tr></thead>
    <tbody>
      @forelse ($txs as $tx)
        <tr>
          <td class="small muted">{{ $tx->created_at->format('d M H:i:s') }}</td>
          <td><span class="tag s2">{{ $tx->event_type }}</span></td>
          <td class="small">{{ $tx->escrow?->order?->product_name ?? '—' }}</td>
          <td>{{ $tx->block_number ?? '—' }}</td>
          <td class="mono">{{ $tx->tx_hash }}</td>
          <td><a href="{{ route('explorer.verify', ['tx' => $tx->tx_hash]) }}">periksa</a></td>
        </tr>
      @empty
        <tr><td colspan="6" class="muted">Belum ada transaksi. Jalankan alur pembayaran di <a href="{{ route('dashboard') }}">Dashboard</a>.</td></tr>
      @endforelse
    </tbody>
  </table>
</div>
@endsection
