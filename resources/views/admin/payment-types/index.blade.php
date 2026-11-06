@extends('layouts.admin')

@section('title', 'Payment Methods')
@section('page_title', 'Payment Methods')

@section('content')
<div class="table-toolbar">
    <h2 style="margin:0; font-size:16px;">Payment Method List</h2>
    @unless ($readOnly)
    <a href="{{ route('admin.payment-types.create') }}" class="btn btn-filled">
        <span class="material-symbols-rounded">add</span> Add Payment Method
    </a>
    @endunless
</div>

<div class="table-wrap">
    <table class="md-table">
        <thead>
            <tr>
                <th>{!! sort_link('Kode', 'code') !!}</th>
                <th>Description</th>
                <th>{!! sort_link('Order', 'order_number') !!}</th>
                <th>{!! sort_link('Count Type', 'count_type') !!}</th>
                <th>SAP Credit Card</th>
                <th>Used in Stores</th>
                <th>{!! sort_link('Status', 'is_active') !!}</th>
                <th style="text-align:right;">Actions</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($paymentTypes as $paymentType)
                @php $gerai = $paymentType->stores; @endphp
                <tr>
                    <td><strong>{{ $paymentType->code }}</strong></td>
                    <td>{{ $paymentType->description }}</td>
                    <td>{{ $paymentType->order_number }}</td>
                    <td>{{ ucfirst($paymentType->count_type) }}</td>
                    <td>{{ $paymentType->creditCard ? $paymentType->creditCard->code.' · '.$paymentType->creditCard->name : '—' }}</td>
                    <td>
                        @forelse ($gerai->take(3) as $store)
                            <span class="badge active">{{ $store->code }}</span>
                        @empty
                            <span style="color: var(--md-on-surface-variant);">—</span>
                        @endforelse
                        @if ($gerai->count() > 3)
                            <span style="font-size:12px; color: var(--md-on-surface-variant);">+{{ $gerai->count() - 3 }} lagi</span>
                        @endif
                    </td>
                    <td><span class="badge {{ $paymentType->is_active ? 'active' : 'inactive' }}">{{ $paymentType->is_active ? 'Aktif' : 'Nonaktif' }}</span></td>
                    <td>
                        <div class="actions-cell">
                            @unless ($readOnly)
                            <a href="{{ route('admin.payment-types.edit', $paymentType) }}" class="btn btn-tonal btn-sm">Edit</a>
                            <form method="POST" action="{{ route('admin.payment-types.destroy', $paymentType) }}" onsubmit="return confirm('Hapus tipe pembayaran ini?')">
                                @csrf @method('DELETE')
                                <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                            </form>
                            @endunless
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="8"><div class="empty-state"><span class="material-symbols-rounded">credit_card</span><p>No payment methods yet.</p></div></td></tr>
            @endforelse
        </tbody>
    </table>
</div>

@include('partials.pagination', ['paginator' => $paymentTypes])
@endsection
