@extends('layouts.admin')

@section('title', 'Units')
@section('page_title', 'Units')

@section('content')
<div class="table-toolbar">
    <h2 style="margin:0; font-size:16px;">Unit List</h2>
    @unless ($readOnly)
    <a href="{{ route('admin.units.create') }}" class="btn btn-filled">
        <span class="material-symbols-rounded">add</span> Add Unit
    </a>
    @endunless
</div>

<div class="table-wrap">
    <table class="md-table">
        <thead>
            <tr>
                <th>{!! sort_link('Code', 'code') !!}</th>
                <th>{!! sort_link('Name', 'name') !!}</th>
                <th>{!! sort_link('Product Count', 'products_count') !!}</th>
                <th>{!! sort_link('Status', 'is_active') !!}</th>
                <th style="text-align:right;">Actions</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($units as $unit)
                <tr>
                    <td><strong class="mono">{{ $unit->code }}</strong></td>
                    <td>{{ $unit->name }}</td>
                    <td>{{ $unit->products_count }}</td>
                    <td><span class="badge {{ $unit->is_active ? 'active' : 'inactive' }}">{{ $unit->is_active ? 'Active' : 'Inactive' }}</span></td>
                    <td>
                        <div class="actions-cell">
                            @unless ($readOnly)
                            <a href="{{ route('admin.units.edit', $unit) }}" class="btn btn-tonal btn-sm">Edit</a>
                            <form method="POST" action="{{ route('admin.units.destroy', $unit) }}" onsubmit="return confirm('Delete unit {{ $unit->code }}?')">
                                @csrf @method('DELETE')
                                <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                            </form>
                            @endunless
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="5"><div class="empty-state"><span class="material-symbols-rounded">straighten</span><p>No units yet.</p></div></td></tr>
            @endforelse
        </tbody>
    </table>
</div>

@include('partials.pagination', ['paginator' => $units])
@endsection
