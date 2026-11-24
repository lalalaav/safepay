{{--
  Satu tempat render aksi pesanan, dua mode:
  - manual (default demo kelas): form POST nyata ke orders.manual, jalan tanpa
    MetaMask dan tanpa JS — HP pun bisa.
  - wallet: tombol data-act yang ditangani safepay.js (tanda tangan MetaMask).
  contract_id TIDAK dikirim dari sini: server ambil dari log kontrak.
--}}
@php
  $cls = trim(($primary ? 'btn-go ' : '') . 'btn-s');
@endphp

@if ($manual)
  <form method="POST" action="{{ route('orders.manual', ['order' => $order->id]) }}" style="display:inline">
    @csrf
    <input type="hidden" name="action" value="{{ $act }}">
    @foreach ($extra as $key => $value)
      <input type="hidden" name="{{ $key }}" value="{{ $value }}">
    @endforeach
    <button type="submit" class="{{ $cls }}">{{ $label }}</button>
  </form>
@else
  <button type="button" class="{{ $cls }}" data-act="{{ $act }}" data-order="{{ $order->id }}"
          data-contract="{{ $contract ?? 0 }}"
          @if ($act === 'pay')
            data-seller="{{ $order->seller->address() }}" data-amount="{{ $order->amount }}"
            data-deadline="{{ now()->addSeconds(30 * 86400)->timestamp }}"
          @endif>{{ $label }}</button>
@endif
