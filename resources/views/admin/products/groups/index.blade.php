@extends('layouts.admin')

@section('title', 'Categories')
@section('page_title', 'Categories')

@section('content')
<div class="table-toolbar">
    <h2 style="margin:0; font-size:16px;">Product Category List</h2>
    @if (auth()->user()->isSuperuser())
        <a href="{{ route('admin.groups.create') }}" class="btn btn-filled">
            <span class="material-symbols-rounded">add</span> Add Category
        </a>
    @endif
</div>

<div class="table-wrap">
    <table class="md-table">
        <thead>
            <tr><th>{!! sort_link('Code', 'code') !!}</th><th>{!! sort_link('Name', 'name') !!}</th><th>Description</th><th>{!! sort_link('Product Count', 'products_count') !!}</th><th>{!! sort_link('Status', 'is_active') !!}</th><th style="text-align:right;">Actions</th></tr>
        </thead>
        <tbody>
            @forelse ($groups as $group)
                <tr>
                    <td><strong>{{ $group->code }}</strong></td>
                    <td>{{ $group->name }}</td>
                    <td>{{ $group->description ?? '-' }}</td>
                    <td>{{ $group->products_count }}</td>
                    <td><span class="badge {{ $group->is_active ? 'active' : 'inactive' }}">{{ $group->is_active ? 'Active' : 'Inactive' }}</span></td>
                    <td>
                        <div class="actions-cell">
                            @if (auth()->user()->isSuperuser())
                                <a href="{{ route('admin.groups.edit', $group) }}" class="btn btn-tonal btn-sm">Edit</a>
                                <form method="POST" action="{{ route('admin.groups.destroy', $group) }}" onsubmit="return confirm('Delete this group?')">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                                </form>
                            @else
                                <span class="badge inactive">Read-only</span>
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="6"><div class="empty-state"><span class="material-symbols-rounded">category</span><p>No product categories yet.</p></div></td></tr>
            @endforelse
        </tbody>
    </table>
</div>

@include('partials.pagination', ['paginator' => $groups])
@endsection