@extends('layouts.admin')

@section('title', 'Customer Groups')
@section('page_title', 'Customer Groups')

@section('content')
<div class="table-toolbar">
    <h2 style="margin:0; font-size:16px;">Customer Groups</h2>
    @unless ($readOnly)
    <a href="{{ route('admin.customer-groups.create') }}" class="btn btn-filled">
        <span class="material-symbols-rounded">add</span> Add Group
    </a>
    @endunless
</div>

<div class="table-wrap">
    <table class="md-table">
        <thead>
            <tr>
                <th>{!! sort_link('Code', 'code') !!}</th>
                <th>{!! sort_link('Name', 'name') !!}</th>
                <th>{!! sort_link('Source', 'source') !!}</th>
                <th style="text-align:right;">Customer</th>
                <th>{!! sort_link('Status', 'is_active') !!}</th>
                <th style="text-align:right;">Actions</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($groups as $group)
                <tr>
                    <td>
                        <strong>{{ $group->code }}</strong>
                        @if ($group->is_default) <span class="badge info">Default</span> @endif
                    </td>
                    <td>{{ $group->name }}</td>
                    <td>{{ $group->isFromSap() ? 'SAP B1' : 'Manual' }}</td>
                    <td style="text-align:right;">{{ $group->customers_count }}</td>
                    <td><span class="badge {{ $group->is_active ? 'active' : 'inactive' }}">{{ $group->is_active ? 'Active' : 'Inactive' }}</span></td>
                    <td>
                        <div class="actions-cell">
                            @unless ($readOnly)
                            <a href="{{ route('admin.customer-groups.edit', $group) }}" class="btn btn-tonal btn-sm">Edit</a>
                            @unless ($group->isFromSap())
                                <form method="POST" action="{{ route('admin.customer-groups.destroy', $group) }}" onsubmit="return confirm('Delete this customer group?')">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                                </form>
                            @endunless
                            @endunless
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="6"><div class="empty-state"><span class="material-symbols-rounded">diversity_3</span><p>No customer groups yet.</p></div></td></tr>
            @endforelse
        </tbody>
    </table>
</div>

@include('partials.pagination', ['paginator' => $groups])
@endsection
