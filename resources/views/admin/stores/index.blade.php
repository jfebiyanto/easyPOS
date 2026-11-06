@extends('layouts.admin')

@section('title', 'Stores')
@section('page_title', 'Stores')

@section('content')
<div class="table-toolbar">
    <div>
        <h2 style="margin:0; font-size:16px;">Store List</h2>
        <p style="margin:2px 0 0; font-size:13px; color:var(--md-on-surface-variant);">
            Master data (products, price lists, promotions) works on the <strong>active store</strong> below.
        </p>
    </div>
    @if (auth()->user()->isSuperuser())
        <a href="{{ route('admin.stores.create') }}" class="btn btn-filled">
            <span class="material-symbols-rounded">add_business</span> Add Store
        </a>
    @endif
</div>

<div class="table-wrap">
    <table class="md-table">
        <thead>
            <tr>
                <th>{!! sort_link('Code', 'code') !!}</th>
                <th>{!! sort_link('Store Name', 'name') !!}</th>
                <th>{!! sort_link('Store Manager', 'pic_name') !!}</th>
                <th>{!! sort_link('VAT', 'ppn_enabled') !!}</th>
                <th>{!! sort_link('User', 'users_count') !!}</th>
                <th>Context Status</th>
                <th style="text-align:right;">Actions</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($stores as $store)
                <tr>
                    <td><strong>{{ $store->code }}</strong></td>
                    <td>
                        {{ $store->name }}<br>
                        <span style="font-size:12px; color:var(--md-on-surface-variant);">{{ \Illuminate\Support\Str::limit($store->address, 60) }}</span>
                    </td>
                    <td>{{ $store->pic_name }}<br><span style="font-size:12px; color:var(--md-on-surface-variant);">{{ $store->pic_phone }}</span></td>
                    <td>{{ $store->ppn_enabled ? $store->ppn_rate.'%' : '-' }}</td>
                    <td>{{ $store->users_count }}</td>
                    <td>
                        @if ($store->id === $activeStoreId)
                            <span class="badge active">Active Store</span>
                        @else
                            <span class="badge inactive">-</span>
                        @endif
                    </td>
                    <td>
                        <div class="actions-cell">
                            @if ($store->id !== $activeStoreId && auth()->user()->isSuperuser())
                                <form method="POST" action="{{ route('admin.stores.switch', $store) }}">
                                    @csrf
                                    <button type="submit" class="btn btn-tonal btn-sm">Set Active</button>
                                </form>
                            @endif
                            @if (auth()->user()->isSuperuser())
                                <a href="{{ route('admin.stores.edit', $store) }}" class="btn btn-tonal btn-sm">Edit</a>
                                <form method="POST" action="{{ route('admin.stores.destroy', $store) }}" onsubmit="return confirm('Delete store {{ $store->code }}?')">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                                </form>
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="7"><div class="empty-state"><span class="material-symbols-rounded">store</span><p>No stores yet. <a href="{{ route('admin.stores.create') }}">Add the first store</a>.</p></div></td></tr>
            @endforelse
        </tbody>
    </table>
</div>

@include('partials.pagination', ['paginator' => $stores])
@endsection