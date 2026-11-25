@extends('layouts.app')
@section('title', 'Dashboard')

@section('content')
@php
  $isBuyer = $user->hasRole('buyer');
  $isSeller = $user->hasRole('seller');
  $isValidator = $user->hasRole('validator');
  $isAuditor = $user->hasRole('auditor');
  $manual = $mode === 'manual';
  $fmtEthRp = fn ($rp) => \App\Services\ChainReader::idrToEth($rp).' ETH';
@endphp

<div class="row" style="justify-content:space-between;align-items:flex-end">
  <div>
    <h1>Dashboard {{ $user->name }}</h1>
    <p class="muted small" style="margin:0">
      Peran: <b>{{ $user->role }}</b> · wallet terdaftar:
      <span class="mono" data-registered-wallet>{{ $user->address() ?? 'belum ada (klik Connect MetaMask)' }}</span>
    </p>
  </div>
  @if ($mode === 'wallet')
    <div class="row">
      <button id="faucet-eth" type="button" class="btn-go btn-s" title="Kirim +1 ETH (setara Rp 1.000.000) gratis dari Faucet Hardhat ke wallet kamu">+1 ETH Demo (Rp 1 Jt)</button>
      <button id="refresh-bal" type="button" class="btn-s">Refresh Saldo</button>
    </div>
  @endif
</div>

@if (! ($health['node_ok'] ?? false) || ! ($health['deployed'] ?? false))
  <div class="flash warn" style="margin-top:14px">
    <b>Status Blockchain:</b> {{ $health['error'] ?? 'Node blockchain belum siap.' }}
  </div>
@endif

<div class="grid g4" style="margin-top:16px">
  @if ($isSeller)
    <div class="kpi">
      <span class="muted small">Saldo wallet kamu (ETH)</span>
      <b data-bal="mine">{{ $myEth !== null ? $myEth.' ETH' : 'n/a' }}</b>
      <div class="muted small" data-bal="mine-rp">{{ $myBalance !== null ? '≈ Rp '.number_format($myBalance, 0, ',', '.') : '' }}</div>
    </div>
    <div class="kpi">
      <span class="muted small">Pembayaran ditahan (escrow)</span>
      <b>{{ $fmtEthRp($sellerHeld ?? 0) }}</b>
      <div class="muted small">Rp {{ number_format($sellerHeld ?? 0, 0, ',', '.') }}</div>
    </div>
    <div class="kpi">
      <span class="muted small">Dana sudah diterima (released)</span>
      <b style="color:var(--ok)">{{ $fmtEthRp($sellerReleased ?? 0) }}</b>
      <div class="muted small">Rp {{ number_format($sellerReleased ?? 0, 0, ',', '.') }}</div>
    </div>
    <div class="kpi"><span class="muted small">Pesanan masuk</span><b>{{ $orders->count() }}</b></div>
  @elseif ($isValidator || $isAuditor)
    <div class="kpi">
      <span class="muted small">Total ETH tertahan kontrak</span>
      <b data-bal="escrow">{{ $escrowEth !== null ? $escrowEth.' ETH' : 'n/a' }}</b>
      <div class="muted small" data-bal="escrow-rp">{{ $chainBalance !== null ? '≈ Rp '.number_format($chainBalance, 0, ',', '.') : '' }}</div>
    </div>
    <div class="kpi"><span class="muted small">Sengketa aktif (OPEN)</span><b style="color:{{ ($disputes->where('status', 'OPEN')->count() > 0) ? 'var(--bad)' : 'var(--ink)' }}">{{ $disputes->where('status', 'OPEN')->count() }}</b></div>
    <div class="kpi"><span class="muted small">Total pesanan / tx</span><b>{{ $orders->count() }} / {{ $txCount ?? 0 }}</b></div>
    <div class="kpi"><span class="muted small">Status Node Hardhat</span><b class="mono" style="font-size:14px">{{ ($health['node_ok'] ?? false) ? 'Aktif · Block #'.$health['block_number'] : 'Mati' }}</b></div>
  @else
    <div class="kpi">
      <span class="muted small">Saldo wallet kamu (ETH)</span>
      <b data-bal="mine">{{ $myEth !== null ? $myEth.' ETH' : 'n/a' }}</b>
      <div class="muted small" data-bal="mine-rp">{{ $myBalance !== null ? '≈ Rp '.number_format($myBalance, 0, ',', '.') : '' }}</div>
    </div>
    <div class="kpi">
      <span class="muted small">Dana tertahan di smart contract</span>
      <b data-bal="escrow">{{ $escrowEth !== null ? $escrowEth.' ETH' : 'n/a' }}</b>
      <div class="muted small" data-bal="escrow-rp">{{ $chainBalance !== null ? '≈ Rp '.number_format($chainBalance, 0, ',', '.') : '' }}</div>
    </div>
    <div class="kpi"><span class="muted small">Pesanan kamu</span><b>{{ $orders->count() }}</b></div>
    <div class="kpi"><span class="muted small">Status Node Hardhat</span><b class="mono" style="font-size:14px">{{ ($health['node_ok'] ?? false) ? 'Aktif · Block #'.$health['block_number'] : 'Mati' }}</b></div>
  @endif
</div>

@if ($isBuyer)
  <h2>Buat pesanan</h2>
  <form method="POST" action="{{ route('orders.store') }}" class="card bar">
    @csrf
    <label>Penjual
      <select name="seller_id" required>
        @foreach ($sellers as $seller)
          <option value="{{ $seller->id }}">{{ $seller->name }} · {{ $seller->address() ?? 'belum ada wallet' }}</option>
        @endforeach
      </select>
    </label>
    <label>Barang / jasa
      <input name="product_name" required placeholder="Contoh: Sepatu">
    </label>
    <label>Harga (Rp) <span id="eth-preview" class="mono" style="color:var(--gold)">= 0.2 ETH</span>
      <input name="amount" type="number" min="1" value="200000" required>
    </label>
    <div style="flex:0 0 auto"><button class="btn-go">Buat pesanan</button></div>
  </form>
@endif

@if (($isValidator || $isAuditor) && $disputes->count())
  <h2>Sengketa / Transaksi Bermasalah</h2>
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>Escrow</th><th>Pesanan</th><th>Nominal (ETH / Rp)</th><th>Alasan sengketa</th>
          <th>Status</th><th>Keputusan</th>
          @if ($isValidator)<th>Aksi Validator</th>@endif
        </tr>
      </thead>
      <tbody>
        @foreach ($disputes as $dispute)
          @php
            $dEscrow = $dispute->escrow;
            $dOrder = $dEscrow?->order;
            $dChain = $dEscrow?->getAttribute('chain');
            $dChainStatus = $dChain['status'] ?? $dEscrow?->status;
          @endphp
          <tr>
            <td class="mono">#{{ $dEscrow?->contract_id }}</td>
            <td>
              {{ $dOrder?->product_name ?? '—' }}
              <div class="small muted">{{ $dOrder?->buyer?->name }} → {{ $dOrder?->seller?->name }}</div>
            </td>
            <td>
              <b>{{ $fmtEthRp($dOrder?->amount ?? 0) }}</b>
              <div class="small muted">Rp {{ number_format($dOrder?->amount ?? 0, 0, ',', '.') }}</div>
            </td>
            <td>{{ $dispute->reason }}</td>
            <td><span class="tag {{ $dispute->status === 'OPEN' ? 's5' : 's3' }}">{{ $dispute->status }}</span></td>
            <td class="small">{{ $dispute->resolution ?? 'Menunggu keputusan validator' }}</td>
            @if ($isValidator)
              <td>
                @if ($dOrder && $dChainStatus === 'DISPUTED')
                  <div class="row">
                    @include('partials.action', ['act' => 'resolve', 'label' => 'Putuskan → penjual', 'primary' => true, 'manual' => $manual, 'order' => $dOrder, 'contract' => $dEscrow->contract_id, 'extra' => ['to_seller' => 1]])
                    @include('partials.action', ['act' => 'resolve', 'label' => 'Putuskan → pembeli', 'primary' => false, 'manual' => $manual, 'order' => $dOrder, 'contract' => $dEscrow->contract_id, 'extra' => ['to_seller' => 0]])
                  </div>
                @else
                  <span class="muted small">Selesai ({{ $dChainStatus }})</span>
                @endif
              </td>
            @endif
          </tr>
        @endforeach
      </tbody>
    </table>
  </div>
@endif

<h2>Pesanan</h2>
<div class="table-wrap">
  <table>
    <thead>
      <tr>
        <th>#</th><th>Barang</th><th>Nominal (ETH / Rp)</th><th>Pembeli</th><th>Penjual</th>
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
          $mismatch = $escrow && $chain && ($escrow->status !== $chain['status'] || (int) $order->amount !== (int) $chain['amount']);
        @endphp
        <tr>
          <td>{{ $order->id }}</td>
          <td>
            {{ $order->product_name }}
            @if ($escrow?->dispute)
              <div class="small" style="color:var(--bad);margin-top:4px">
                Sengketa ({{ $escrow->dispute->status }}): {{ $escrow->dispute->reason }}
                @if ($escrow->dispute->resolution)
                  · <i>{{ $escrow->dispute->resolution }}</i>
                @endif
              </div>
            @endif
          </td>
          <td>
            <b>{{ $fmtEthRp($order->amount) }}</b>
            <div class="small muted">Rp {{ number_format($order->amount, 0, ',', '.') }}</div>
          </td>
          <td>{{ $order->buyer->name }}<div class="mono muted">{{ $order->buyer->address() }}</div></td>
          <td>{{ $order->seller->name }}<div class="mono muted">{{ $order->seller->address() }}</div></td>
          <td>
            <span class="tag {{ $mismatch ? 's5' : '' }}">{{ $escrow?->status ?? 'belum tercatat' }}</span>
            @if ($mismatch)
              <div class="small" style="color:var(--bad);margin-top:4px">⚠ Beda dengan blockchain!</div>
            @endif
          </td>
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
              $deadlineTxt = $escrow?->deadline ? date('d M Y H:i', (int) $escrow->deadline) : null;
            @endphp
            <div class="row">
              @if ($mineBuyer && $status === 'CREATED')
                @include('partials.action', ['act' => 'pay', 'label' => 'Bayar '.$fmtEthRp($order->amount), 'primary' => true, 'manual' => $manual, 'order' => $order, 'contract' => $contract, 'extra' => []])
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
</div>

<p class="muted small" style="margin-top:14px">
  Kurs simulasi tetap: <code>1 ETH = Rp 1.000.000</code> (contoh: Rp 150.000 = 0.15 ETH).
  Dana ETH benar-benar dikunci di alamat Smart Contract dan dibaca lewat JSON-RPC — bukan dari MySQL.
</p>
@endsection
