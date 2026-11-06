@extends('layouts.admin')

@section('title', 'Currencies')
@section('page_title', 'Currencies')

@section('content')
<div class="table-toolbar">
    <h2 style="margin:0; font-size:16px;">Currency List</h2>
    @if (auth()->user()->isSuperuser())
        <a href="{{ route('admin.currencies.create') }}" class="btn btn-filled">
            <span class="material-symbols-rounded">add</span> Add Currency
        </a>
    @endif
</div>

<div class="table-wrap">
    <table class="md-table">
        <thead>
            <tr>
                <th>{!! sort_link('Kode (ISO)', 'code') !!}</th>
                <th>{!! sort_link('Simbol', 'symbol') !!}</th>
                <th>{!! sort_link('Rounding', 'has_rounding') !!}</th>
                <th>{!! sort_link('Digit Desimal', 'decimal_places') !!}</th>
                <th>{!! sort_link('Pecahan', 'denominations_count') !!}</th>
                <th>{!! sort_link('Status', 'is_active') !!}</th>
                <th style="text-align:right;">Actions</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($currencies as $currency)
                <tr>
                    <td><strong>{{ $currency->code }}</strong></td>
                    <td>{{ $currency->symbol }}</td>
                    <td>@if ($currency->has_rounding)<span class="badge info">Rounding</span>@else — @endif</td>
                    <td>{{ $currency->decimal_places }}</td>
                    <td>{{ $currency->denominations_count }}</td>
                    <td><span class="badge {{ $currency->is_active ? 'active' : 'inactive' }}">{{ $currency->is_active ? 'Aktif' : 'Nonaktif' }}</span></td>
                    <td>
                        <div class="actions-cell">
                            <a href="{{ route('admin.currencies.show', $currency) }}" class="btn btn-tonal btn-sm">Denominations</a>
                            @if (auth()->user()->isSuperuser())
                                <a href="{{ route('admin.currencies.edit', $currency) }}" class="btn btn-tonal btn-sm">Edit</a>
                                <form method="POST" action="{{ route('admin.currencies.destroy', $currency) }}" onsubmit="return confirm('Hapus mata uang ini beserta seluruh nominal pecahannya?')">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                                </form>
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="7"><div class="empty-state"><span class="material-symbols-rounded">currency_exchange</span><p>No currencies yet.</p></div></td></tr>
            @endforelse
        </tbody>
    </table>
</div>

@include('partials.pagination', ['paginator' => $currencies])
@endsection
