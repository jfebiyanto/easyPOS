@extends('layouts.admin')

@section('title', 'Tutup Toko (EOD)')
@section('page_title', 'Tutup Toko (EOD)')

@section('content')
<div class="table-toolbar">
    <form method="GET" action="{{ route('admin.eod.index') }}" style="display:flex; gap:8px; align-items:flex-end; flex-wrap:wrap;">
        @if ($stores->isNotEmpty())
            <div class="field" style="margin:0;">
                <label for="store">Gerai</label>
                <select id="store" name="store" onchange="this.form.submit()">
                    @foreach ($stores as $s)
                        <option value="{{ $s->id }}" @selected($storeId === $s->id)>{{ $s->code }} · {{ $s->name }}</option>
                    @endforeach
                </select>
            </div>
        @endif
        <div class="field" style="margin:0;">
            <label for="date">Tanggal Bisnis</label>
            <input type="date" id="date" name="date" value="{{ $businessDate }}" onchange="this.form.submit()">
        </div>
    </form>
    <a href="{{ route('admin.stock-review.index') }}" class="btn btn-tonal">
        <span class="material-symbols-rounded">rule</span> Review Stok ({{ $reviewCount }})
    </a>
</div>

<div class="stat-grid">
    <div class="stat-card">
        <div class="stat-icon"><span class="material-symbols-rounded">receipt_long</span></div>
        <div><div class="stat-value money">{{ 'Rp '.number_format($salesTotal, 0, ',', '.') }}</div><div class="stat-label">Total penjualan</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon"><span class="material-symbols-rounded">payments</span></div>
        <div><div class="stat-value money">{{ 'Rp '.number_format($cashExpected, 0, ',', '.') }}</div><div class="stat-label">Kas seharusnya (tunai)</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon"><span class="material-symbols-rounded">rule</span></div>
        <div><div class="stat-value">{{ $reviewCount }}</div><div class="stat-label">Transaksi review pending</div></div>
    </div>
</div>

@php
    $isClosed = $closure && $closure->closed_at;
    $canReopen = $isClosed && $businessDate === company_today();
@endphp

@if ($isClosed)
    <div class="md-snackbar success">
        <span class="material-symbols-rounded">lock</span>
        Toko sudah ditutup untuk {{ $businessDate }}. Kas dihitung: Rp {{ number_format($closure->cash_counted, 0, ',', '.') }}
        · Selisih: Rp {{ number_format($closure->cash_variance, 0, ',', '.') }}
    </div>
@endif

@if ($closure?->reopened_at)
    <p class="card-subtitle">
        Terakhir dibuka ulang {{ $closure->reopened_at->timezone(company_timezone())->format('d/m/Y H:i') }}
        oleh {{ $closure->reopenedBy?->name ?? '-' }} · Alasan: {{ $closure->reopen_reason }}
    </p>
@endif

@if ($isClosed)
    <div class="card">
        <h2 class="card-title">Buka Ulang EOD</h2>
        @if ($canReopen)
            <p class="card-subtitle">Transaksi POS tanggal ini terkunci. Buka ulang EOD untuk melanjutkan transaksi, lalu tutup kembali di akhir hari.</p>
            <form method="POST" action="{{ route('admin.eod.reopen') }}">
                @csrf
                <input type="hidden" name="business_date" value="{{ $businessDate }}">
                <input type="hidden" name="store_id" value="{{ $storeId }}">
                <div class="field @error('reopen_reason') has-error @enderror">
                    <label for="reopen_reason">Alasan buka ulang <span style="color: var(--md-error);">*</span></label>
                    <input type="text" id="reopen_reason" name="reopen_reason" value="{{ old('reopen_reason') }}" maxlength="500" required>
                    @error('reopen_reason') <div class="error">{{ $message }}</div> @enderror
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn btn-tonal">
                        <span class="material-symbols-rounded">lock_open</span> Buka Ulang EOD
                    </button>
                </div>
            </form>
        @else
            <p class="card-subtitle">EOD hanya dapat dibuka ulang pada tanggal bisnis yang sama dengan tanggal sistem ({{ company_today() }}).</p>
        @endif
    </div>
@endif

<form method="POST" action="{{ route('admin.eod.store') }}">
    @csrf
    <input type="hidden" name="business_date" value="{{ $businessDate }}">
    @if ($storeId)
        <input type="hidden" name="store_id" value="{{ $storeId }}">
    @endif

    <div class="card">
        <h2 class="card-title">Hitung Kas per Pecahan</h2>
        <p class="card-subtitle">Masukkan jumlah lembar/koin per pecahan. Total dihitung otomatis.</p>

        <div class="table-wrap">
            <table class="md-table">
                <thead>
                    <tr><th>Pecahan</th><th style="width:160px;">Jumlah</th><th style="text-align:right;">Subtotal</th></tr>
                </thead>
                <tbody>
                    @forelse ($denominations as $d)
                        <tr>
                            <td class="money">Rp {{ number_format((float) $d->value, 0, ',', '.') }}</td>
                            <td>
                                <input type="number" min="0" name="denominations[{{ (int) $d->value }}]"
                                       value="{{ old('denominations.'.(int) $d->value) }}"
                                       class="denom-input" data-value="{{ (int) $d->value }}"
                                       style="width:100%; padding:8px; border:1px solid var(--md-outline); border-radius:var(--radius-sm);">
                            </td>
                            <td style="text-align:right;" class="money" data-subtotal="{{ (int) $d->value }}">Rp 0</td>
                        </tr>
                    @empty
                        <tr><td colspan="3"><div class="empty-state">Pecahan mata uang belum diatur di modul Mata Uang.</div></td></tr>
                    @endforelse
                </tbody>
                <tfoot>
                    <tr>
                        <th colspan="2" style="text-align:right;">Total Kas Dihitung</th>
                        <th style="text-align:right;" class="money" id="cash-total">Rp 0</th>
                    </tr>
                </tfoot>
            </table>
        </div>

        <div class="field" style="margin-top:16px;">
            <label for="note">Catatan</label>
            <input type="text" id="note" name="note" value="{{ old('note') }}" maxlength="500">
        </div>

        @if (auth()->user()->isSuperuser() && $reviewCount > 0)
            <div class="field">
                <div class="checkbox-row">
                    <input type="checkbox" id="override_review" name="override_review" value="1">
                    <label for="override_review">Override: tutup meski masih ada review pending (Owner)</label>
                </div>
            </div>
        @endif

        <div class="form-actions">
            <button type="submit" class="btn btn-filled" @disabled(! $storeId || $isClosed)>
                <span class="material-symbols-rounded">lock</span> Tutup Toko
            </button>
        </div>
    </div>
</form>

@push('scripts')
<script>
(function () {
    var inputs = document.querySelectorAll('.denom-input');
    var total = document.getElementById('cash-total');

    function fmt(n) { return 'Rp ' + n.toLocaleString('id-ID'); }

    function recalc() {
        var sum = 0;
        inputs.forEach(function (input) {
            var value = parseInt(input.dataset.value, 10);
            var count = parseInt(input.value || '0', 10);
            var sub = value * (isNaN(count) ? 0 : count);
            sum += sub;
            var cell = document.querySelector('[data-subtotal="' + value + '"]');
            if (cell) cell.textContent = fmt(sub);
        });
        total.textContent = fmt(sum);
    }

    inputs.forEach(function (input) { input.addEventListener('input', recalc); });
    recalc();
})();
</script>
@endpush
@endsection
