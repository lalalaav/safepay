@extends('layouts.app')
@section('title', 'Dashboard')

@section('content')
@php
  $isBuyer = $user->hasRole('buyer');
  $isSeller = $user->hasRole('seller');
  $isValidator = $user->hasRole('validator');
  $isAuditor = $user->hasRole('auditor');
@endphp

<h1>Dashboard {{ $user->name }}</h1>
<p class="muted small">
  Peran: <b>{{ $user->role }}</b> · wallet terdaftar:
  <span class="mono">{{ $user->address() ?? 'belum ada' }}</span>
</p>

<div class="grid g4" style="margin-top:16px">
  <div class="kpi"><span class="muted small">Saldo wallet kamu (mIDR)</span><b data-bal="mine">{{ $myBalance !== null ? 'Rp '.number_format($myBalance, 0, ',', '.') : 'n/a' }}</b></div>
  <div class="kpi"><span class="muted small">Dana tertahan di smart contract</span><b data-bal="escrow">{{ $chainBalance === null ? 'n/a' : 'Rp '.number_format($chainBalance, 0, ',', '.') }}</b></div>
  <div class="kpi"><span class="muted small">Pesanan terlihat</span><b>{{ $orders->count() }}</b></div>
  <div class="kpi"><span class="muted small">Node RPC</span><b class="mono" style="font-size:13px">{{ config('safepay.rpc') }}</b></div>
</div>

@if ($isBuyer)
  <h2>Buat pesanan</h2>
  <form method="POST" action="{{ route('orders.store') }}" class="card bar">
    @csrf
    <label>Penjual
      <select name="seller_id" required>
        @foreach ($sellers as $seller)
          <option value="{{ $seller->id }}">{{ $seller->name }} · {{ $seller->address() }}</option>
        @endforeach
      </select>
    </label>
    <label>Barang / jasa
      <input name="product_name" required placeholder="Contoh: Sepatu">
    </label>
    <label>Harga (Rp)
      <input name="amount" type="number" min="1" value="200000" required>
    </label>
    <div style="flex:0 0 auto"><button class="btn-go">Buat pesanan</button></div>
  </form>
@endif

<h2>Pesanan</h2>
<table>
  <thead>
    <tr>
      <th>#</th><th>Barang</th><th>Nominal</th><th>Pembeli</th><th>Penjual</th>
      <th>Status MySQL</th><th>Status blockchain</th><th>Aksi</th>
    </tr>
  </thead>
  <tbody>
    @forelse ($orders as $order)
      @php
        $escrow = $order->escrow;
        $contract = $escrow?->contract_id;
        $chain = $escrow?->getAttribute('chain');
        $status = $chain['status'] ?? $escrow?->status ?? 'CREATED';
        $tone = ['CREATED' => 's1', 'FUNDED' => 's1', 'SHIPPED' => 's2', 'RELEASED' => 's3', 'REFUNDED' => 's4', 'DISPUTED' => 's5'][$status] ?? '';
      @endphp
      <tr>
        <td>{{ $order->id }}</td>
        <td>{{ $order->product_name }}</td>
        <td>Rp {{ number_format($order->amount, 0, ',', '.') }}</td>
        <td>{{ $order->buyer->name }}<div class="mono muted">{{ $order->buyer->address() }}</div></td>
        <td>{{ $order->seller->name }}<div class="mono muted">{{ $order->seller->address() }}</div></td>
        <td><span class="tag">{{ $escrow?->status ?? 'belum tercatat' }}</span></td>
        <td>
          @if ($chain)
            <span class="tag {{ $tone }}">{{ $chain['status'] }}</span>
            <div class="mono muted">escrow id {{ $contract }}</div>
          @else
            <span class="tag">—</span>
          @endif
        </td>
        <td>
          @php
            $mineBuyer = $isBuyer && $order->buyer_id === $user->id;
            $mineSeller = $isSeller && $order->seller_id === $user->id;
            $manual = $mode === 'manual';
            $deadlineTxt = $escrow?->deadline ? date('d M Y H:i', (int) $escrow->deadline) : null;
          @endphp
          <div class="row">
            @if ($mineBuyer && $status === 'CREATED')
              @include('partials.action', ['act' => 'pay', 'label' => 'Bayar & kunci dana', 'primary' => true, 'manual' => $manual, 'order' => $order, 'contract' => $contract, 'extra' => []])
            @endif

            @if ($mineSeller && $status === 'FUNDED')
              @include('partials.action', ['act' => 'ship', 'label' => 'Tandai dikirim', 'primary' => true, 'manual' => $manual, 'order' => $order, 'contract' => $contract, 'extra' => []])
            @endif

            @if ($mineBuyer && $status === 'SHIPPED')
              @include('partials.action', ['act' => 'confirm', 'label' => 'Barang diterima', 'primary' => true, 'manual' => $manual, 'order' => $order, 'contract' => $contract, 'extra' => []])
            @endif

            @if (($mineBuyer || $mineSeller) && in_array($status, ['FUNDED', 'SHIPPED'], true))
              @include('partials.action', ['act' => 'dispute', 'label' => 'Ajukan sengketa', 'primary' => false, 'manual' => $manual, 'order' => $order, 'contract' => $contract, 'extra' => ['reason' => 'Barang tidak sesuai deskripsi']])
            @endif

            @if ($mineBuyer && in_array($status, ['FUNDED', 'SHIPPED'], true))
              @include('partials.action', ['act' => 'refund', 'label' => $status === 'FUNDED' ? 'Batalkan & refund' : 'Refund (setelah deadline)', 'primary' => false, 'manual' => $manual, 'order' => $order, 'contract' => $contract, 'extra' => []])
            @endif

            @if ($isValidator && $status === 'DISPUTED')
              @include('partials.action', ['act' => 'resolve', 'label' => 'Putuskan → penjual', 'primary' => true, 'manual' => $manual, 'order' => $order, 'contract' => $contract, 'extra' => ['to_seller' => 1]])
              @include('partials.action', ['act' => 'resolve', 'label' => 'Putuskan → pembeli', 'primary' => false, 'manual' => $manual, 'order' => $order, 'contract' => $contract, 'extra' => ['to_seller' => 0]])
            @endif

            @if (! $mineBuyer && ! $mineSeller && ! $isValidator)
              <span class="muted small">pantau saja — bukti on-chain di <a href="{{ route('explorer') }}">Explorer</a></span>
            @endif
            @if ($mineBuyer && ! $escrow)
              <form method="POST" action="{{ route('orders.destroy', ['order' => $order->id]) }}" style="display:inline"
                    onsubmit="return confirm('Hapus pesanan #{{ $order->id }}? Belum ada dana di kontrak.')">
                @csrf
                <button type="submit" class="btn-s">Hapus</button>
              </form>
            @endif
          </div>

          @if ($deadlineTxt && in_array($status, ['FUNDED', 'SHIPPED'], true))
            <div class="small muted">deadline refund: {{ $deadlineTxt }}</div>
          @endif

          @if ($escrow?->transactions->count())
            <div class="small" style="margin-top:6px">
              @foreach ($escrow->transactions as $tx)
                <div><a href="{{ route('explorer.verify', ['tx' => $tx->tx_hash]) }}">{{ $tx->event_type }}</a>
                  <span class="mono muted">{{ $tx->tx_hash }}</span></div>
              @endforeach
            </div>
          @endif
        </td>
      </tr>
    @empty
      <tr><td colspan="8" class="muted">Belum ada pesanan.</td></tr>
    @endforelse
  </tbody>
</table>

<p class="muted small" style="margin-top:14px">
  Status blockchain dibaca langsung dari smart contract lewat <code>eth_call</code> — bukan dari MySQL.
  Kalau dua kolom status berbeda, yang benar adalah kolom blockchain.
</p>
@endsection
