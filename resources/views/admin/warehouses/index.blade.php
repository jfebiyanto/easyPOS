@extends('layouts.admin')

@section('title', 'Warehouses')
@section('page_title', 'Warehouses')

@section('content')
<div class="table-toolbar">
    <h2 style="margin:0; font-size:16px;">Warehouse List</h2>
    @unless ($readOnly)
    <a href="{{ route('admin.warehouses.create') }}" class="btn btn-filled">
        <span class="material-symbols-rounded">add</span> Add Warehouse
    </a>
    @endunless
</div>

<div class="table-wrap">
    <table class="md-table">
        <thead>
            <tr><th>{!! sort_link('Code', 'code') !!}</th><th>{!! sort_link('Name', 'name') !!}</th><th>{!! sort_link('Profit Center', 'profit_center') !!}</th><th>{!! sort_link('Retail', 'is_retail') !!}</th><th>Store</th><th>{!! sort_link('Status', 'is_active') !!}</th><th style="text-align:right;">Actions</th></tr>
        </thead>
        <tbody>
            @forelse ($warehouses as $warehouse)
                <tr>
                    <td><strong>{{ $warehouse->code }}</strong></td>
                    <td>{{ $warehouse->name }}</td>
                    <td>{{ $warehouse->profit_center ?: '-' }}</td>
                    <td>{{ $warehouse->is_retail ? 'Yes' : 'No' }}</td>
                    <td>{{ $warehouse->store ? $warehouse->store->code.' · '.$warehouse->store->name : '-' }}</td>
                    <td><span class="badge {{ $warehouse->is_active ? 'active' : 'inactive' }}">{{ $warehouse->is_active ? 'Active' : 'Inactive' }}</span></td>
                    <td>
                        <div class="actions-cell">
                            @unless ($readOnly)
                            <a href="{{ route('admin.warehouses.edit', $warehouse) }}" class="btn btn-tonal btn-sm">Edit</a>
                            <form method="POST" action="{{ route('admin.warehouses.destroy', $warehouse) }}" onsubmit="return confirm('Delete this warehouse?')">
                                @csrf @method('DELETE')
                                <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                            </form>
                            @endunless
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="7"><div class="empty-state"><span class="material-symbols-rounded">warehouse</span><p>No warehouses yet.</p></div></td></tr>
            @endforelse
        </tbody>
    </table>
</div>

@include('partials.pagination', ['paginator' => $warehouses])
@endsection
