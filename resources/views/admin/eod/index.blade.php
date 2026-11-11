@extends('layouts.admin')

@section('title', 'End of Day (EOD)')
@section('page_title', 'End of Day (EOD)')

@section('content')
<div class="table-toolbar">
    <form method="GET" action="{{ route('admin.eod.index') }}" style="display:flex; gap:8px; align-items:flex-end; flex-wrap:wrap;">
        @if ($stores->isNotEmpty())
            <div class="field" style="margin:0;">
                <label for="store">Store</label>
                <select id="store" name="store" onchange="this.form.submit()">
                    @foreach ($stores as $s)
                        <option value="{{ $s->id }}" @selected($storeId === $s->id)>{{ $s->code }} · {{ $s->name }}</option>
                    @endforeach
                </select>
            </div>
        @endif
        <div class="field" style="margin:0;">
            <label for="date">Business Date</label>
            <input type="date" id="date" name="date" value="{{ $businessDate }}" onchange="this.form.submit()">
        </div>
    </form>
    <a href="{{ route('admin.stock-review.index') }}" class="btn btn-tonal">
        <span class="material-symbols-rounded">rule</span> Stock Review ({{ $reviewCount }})
    </a>
</div>

<div class="stat-grid">
    <div class="stat-card">
        <div class="stat-icon"><span class="material-symbols-rounded">receipt_long</span></div>
        <div><div class="stat-value money">{{ 'Rp '.number_format($salesTotal, 0, ',', '.') }}</div><div class="stat-label">Total sales</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon"><span class="material-symbols-rounded">payments</span></div>
        <div><div class="stat-value money">{{ 'Rp '.number_format($cashExpected, 0, ',', '.') }}</div><div class="stat-label">Expected cash (cash)</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon"><span class="material-symbols-rounded">rule</span></div>
        <div><div class="stat-value">{{ $reviewCount }}</div><div class="stat-label">Pending review transactions</div></div>
    </div>
</div>

@php
    $isClosed = $closure && $closure->closed_at;
    $canReopen = $isClosed && $businessDate === company_today();
@endphp

@if ($storeId && ! $closure?->opened_at)
    <div class="md-snackbar">
        <span class="material-symbols-rounded">storefront</span>
        The store was not opened for {{ $businessDate }}. A cashier opens the store from the POS (Open Store), so there is nothing to close for this date.
    </div>
@endif

@if ($closure?->opened_at)
    <div class="card">
        <h2 class="card-title">Store Opening &amp; Cashier Shifts</h2>
        <p class="card-subtitle">
            Opened by <strong>{{ $closure->openedBy?->name ?? '-' }}</strong>
            at {{ $closure->opened_at->timezone(company_timezone())->format('d/m/Y H:i') }}
            · Total opening float: <span class="money">Rp {{ number_format($openingFloatTotal, 0, ',', '.') }}</span> (included in expected cash)
        </p>
        @if ($activeShifts->isNotEmpty())
            <div class="md-snackbar">
                <span class="material-symbols-rounded">pending_actions</span>
                {{ $activeShifts->count() }} cashier shift(s) are still active ({{ $activeShifts->map(fn ($shift) => $shift->user?->name)->implode(', ') }}). EOD can only be closed after every shift has ended.
            </div>
            @unless ($readOnly ?? false)
                <form method="POST" action="{{ route('admin.eod.force-end-shifts') }}" style="margin-bottom:12px;">
                    @csrf
                    <input type="hidden" name="business_date" value="{{ $businessDate }}">
                    <input type="hidden" name="store_id" value="{{ $storeId }}">
                    <button type="submit" class="btn btn-tonal"><span class="material-symbols-rounded">stop_circle</span> Force End Shift</button>
                </form>
            @endunless
        @endif
        <div class="table-wrap">
            <table class="md-table">
                <thead>
                    <tr><th>Cashier</th><th>Started</th><th>Ended</th><th style="text-align:right;">Standard</th><th style="text-align:right;">Counted</th><th style="text-align:right;">Difference</th><th>Remark</th></tr>
                </thead>
                <tbody>
                    @forelse ($shifts as $shift)
                        <tr>
                            <td>{{ $shift->user?->name ?? '-' }}@if ($shift->opened_store) <span class="hint">· opened the store</span>@endif</td>
                            <td>{{ $shift->started_at->timezone(company_timezone())->format('H:i') }}</td>
                            <td>
                                @if ($shift->ended_at)
                                    {{ $shift->ended_at->timezone(company_timezone())->format('H:i') }} · {{ \App\Models\CashierShift::ENDED_LABELS[$shift->ended_reason] ?? $shift->ended_reason }}
                                @else
                                    Active
                                @endif
                            </td>
                            <td style="text-align:right;" class="money">{{ 'Rp '.number_format($shift->opening_float_expected, 0, ',', '.') }}</td>
                            <td style="text-align:right;" class="money">{{ 'Rp '.number_format($shift->opening_float, 0, ',', '.') }}</td>
                            <td style="text-align:right;" class="money">{{ (float) $shift->opening_float_variance === 0.0 ? '-' : 'Rp '.number_format($shift->opening_float_variance, 0, ',', '.') }}</td>
                            <td>{{ $shift->opening_float_remark ?? '' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7"><div class="empty-state">No cashier shifts on this date.</div></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endif

@if ($isClosed)
    <div class="md-snackbar success">
        <span class="material-symbols-rounded">lock</span>
        The store is closed for {{ $businessDate }}. Counted cash: Rp {{ number_format($closure->cash_counted, 0, ',', '.') }}
        · Variance: Rp {{ number_format($closure->cash_variance, 0, ',', '.') }}
    </div>
@endif

@if ($closure?->reopened_at)
    <p class="card-subtitle">
        Last reopened {{ $closure->reopened_at->timezone(company_timezone())->format('d/m/Y H:i') }}
        by {{ $closure->reopenedBy?->name ?? '-' }} · Reason: {{ $closure->reopen_reason }}
    </p>
@endif

@if ($isClosed)
    <div class="card">
        <h2 class="card-title">Reopen EOD</h2>
        @if ($canReopen)
            <p class="card-subtitle">POS transactions for this date are locked. Reopen EOD to continue transacting, then close again at the end of the day.</p>
            <form method="POST" action="{{ route('admin.eod.reopen') }}">
                @csrf
                <input type="hidden" name="business_date" value="{{ $businessDate }}">
                <input type="hidden" name="store_id" value="{{ $storeId }}">
                <div class="field @error('reopen_reason') has-error @enderror">
                    <label for="reopen_reason">Reason for reopening <span style="color: var(--md-error);">*</span></label>
                    <input type="text" id="reopen_reason" name="reopen_reason" value="{{ old('reopen_reason') }}" maxlength="500" required>
                    @error('reopen_reason') <div class="error">{{ $message }}</div> @enderror
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn btn-tonal">
                        <span class="material-symbols-rounded">lock_open</span> Reopen EOD
                    </button>
                </div>
            </form>
        @else
            <p class="card-subtitle">EOD can only be reopened on a business date equal to the system date ({{ company_today() }}).</p>
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
        <h2 class="card-title">Confirm Payment Methods</h2>
        <p class="card-subtitle">Every payment method must be confirmed against its expected amount before the store can be closed.</p>
        @error('confirm') <div class="error" style="margin-bottom:8px;">{{ $message }}</div> @enderror
        <div class="table-wrap">
            <table class="md-table">
                <thead>
                    <tr><th>Payment Method</th><th>Type</th><th>Count Type</th><th style="text-align:right;">Expected</th><th style="width:140px; text-align:center;">Confirm</th></tr>
                </thead>
                <tbody>
                    @forelse ($paymentLines as $line)
                        <tr>
                            <td>{{ $line['code'] }}@if ($line['description']) <span style="color: var(--md-on-surface-variant);">· {{ $line['description'] }}</span>@endif</td>
                            <td>{{ \App\Models\PaymentType::KINDS[$line['kind']] ?? $line['kind'] }}</td>
                            <td>{{ \App\Models\PaymentType::COUNT_TYPES[$line['count_type']] ?? $line['count_type'] }}</td>
                            <td style="text-align:right;" class="money">{{ 'Rp '.number_format($line['expected'], 0, ',', '.') }}</td>
                            <td style="text-align:center;">
                                @if ($line['counted_by_denomination'])
                                    <span class="hint">Counted below</span>
                                @else
                                    <input type="checkbox" name="confirm[{{ $line['id'] }}]" value="1" aria-label="Confirm {{ $line['code'] }}" @checked(old('confirm.'.$line['id'])) @disabled($isClosed)>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5"><div class="empty-state">No payment methods are set up for this store.</div></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @if ($countByDenomination)
    <div class="card">
        <h2 class="card-title">Count Cash by Denomination</h2>
        <p class="card-subtitle">Enter the number of notes/coins per denomination. The total is calculated automatically.</p>

        @error('denominations') <div class="error" style="margin-bottom:8px;">{{ $message }}</div> @enderror
        <div class="table-wrap">
            <table class="md-table">
                <thead>
                    <tr><th>Denominations</th><th style="width:160px;">Quantity</th><th style="text-align:right;">Subtotal</th></tr>
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
                        <tr><td colspan="3"><div class="empty-state">Currency denominations have not been set up in the Currencies module.</div></td></tr>
                    @endforelse
                </tbody>
                <tfoot>
                    <tr>
                        <th colspan="2" style="text-align:right;">Total Counted Cash</th>
                        <th style="text-align:right;" class="money" id="cash-total">Rp 0</th>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
    @endif

    <div class="card">
        <div class="field">
            <label for="note">Notes</label>
            <input type="text" id="note" name="note" value="{{ old('note') }}" maxlength="500">
        </div>

        @if (auth()->user()->isSuperuser() && $reviewCount > 0)
            <div class="field">
                <div class="checkbox-row">
                    <input type="checkbox" id="override_review" name="override_review" value="1">
                    <label for="override_review">Override: close even with pending reviews (Owner)</label>
                </div>
            </div>
        @endif

        <div class="form-actions">
            <button type="submit" class="btn btn-filled" @disabled(! $storeId || $isClosed || ! $isOpen || $activeShifts->isNotEmpty())>
                <span class="material-symbols-rounded">lock</span> Close Store
            </button>
        </div>
    </div>
</form>

@push('scripts')
<script>
(function () {
    var inputs = document.querySelectorAll('.denom-input');
    var total = document.getElementById('cash-total');
    if (!total) { return; }

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
