@extends('layouts.app')
@section('title', 'Blockchain Explorer')

@section('content')
<h1>Blockchain Explorer</h1>
<p class="muted small">
  Halaman ini <b>baca-saja</b>: hanya menampilkan bukti dari blockchain. Transaksi dibuat dari
  <a href="{{ route('dashboard') }}">Dashboard</a>.
</p>

<p class="muted small">
  Dana tertahan di kontrak: <b>{{ $chainBalance === null ? 'node mati' : 'Rp '.number_format($chainBalance, 0, ',', '.') }}</b>
  · kontrak escrow: <span class="mono">{{ config('safepay.escrow') }}</span>
</p>

<form method="GET" action="{{ route('explorer') }}" class="card bar" style="margin-top:14px">
  <label>Cari transaction hash
    <input name="tx" value="{{ $query }}" placeholder="0x…">
  </label>
  <div style="flex:0 0 auto"><button class="btn-go">Cari</button></div>
</form>

<h2>Status escrow di smart contract</h2>
<table>
  <thead><tr><th>Escrow id</th><th>Pesanan</th><th>Pembeli (on-chain)</th><th>Penjual (on-chain)</th><th>Nominal</th><th>Status</th><th>Deadline</th></tr></thead>
  <tbody>
    @forelse ($escrows as $escrow)
      @php $c = $escrow->getAttribute('chain'); @endphp
      <tr>
        <td>{{ $escrow->contract_id }}</td>
        <td>{{ $escrow->order?->product_name ?? '—' }}</td>
        @if ($c)
          <td class="mono">{{ $c['buyer'] }}</td>
          <td class="mono">{{ $c['seller'] }}</td>
          <td>Rp {{ number_format($c['amount'], 0, ',', '.') }}</td>
          <td><span class="tag s1">{{ $c['status'] }}</span></td>
          <td class="small muted">{{ date('d M Y H:i', $c['deadline']) }}</td>
        @else
          <td colspan="5" class="muted small">node blockchain tidak bisa dibaca</td>
        @endif
      </tr>
    @empty
      <tr><td colspan="7" class="muted">Belum ada escrow.</td></tr>
    @endforelse
  </tbody>
</table>

<h2>Riwayat transaksi tercatat</h2>
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
@endsection
