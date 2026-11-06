@extends('layouts.admin')

@section('title', 'Tax Groups')
@section('page_title', 'Tax Groups')

@section('content')
<div class="table-toolbar">
    <h2 style="margin:0; font-size:16px;">Tax Group (Output Tax)</h2>
    @unless ($readOnly)
    <a href="{{ route('admin.tax-groups.create') }}" class="btn btn-filled">
        <span class="material-symbols-rounded">add</span> Add Tax Group
    </a>
    @endunless
</div>

<div class="table-wrap">
    <table class="md-table">
        <thead>
            <tr><th>{!! sort_link('Code', 'code') !!}</th><th>{!! sort_link('Name', 'name') !!}</th><th style="text-align:right;">{!! sort_link('Rate', 'rate') !!}</th><th>{!! sort_link('Source', 'source') !!}</th><th>{!! sort_link('Status', 'is_active') !!}</th><th style="text-align:right;">Actions</th></tr>
        </thead>
        <tbody>
            @forelse ($taxGroups as $taxGroup)
                <tr>
                    <td>
                        <strong>{{ $taxGroup->code }}</strong>
                        @if ($taxGroup->id === $selectedId) <span class="badge info">In use</span> @endif
                    </td>
                    <td>{{ $taxGroup->name }}</td>
                    <td style="text-align:right;">{{ \App\Models\TaxGroup::formatRate((float) $taxGroup->rate) }}%</td>
                    <td>{{ $taxGroup->isFromSap() ? 'SAP B1' : 'Manual' }}</td>
                    <td><span class="badge {{ $taxGroup->is_active ? 'active' : 'inactive' }}">{{ $taxGroup->is_active ? 'Active' : 'Inactive' }}</span></td>
                    <td>
                        <div class="actions-cell">
                            @if ($taxGroup->isFromSap())
                                <span class="hint">Read-only (from SAP)</span>
                            @else
                                @unless ($readOnly)
                                <a href="{{ route('admin.tax-groups.edit', $taxGroup) }}" class="btn btn-tonal btn-sm">Edit</a>
                                <form method="POST" action="{{ route('admin.tax-groups.destroy', $taxGroup) }}" onsubmit="return confirm('Delete this Tax Group?')">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                                </form>
                            @endif
                                @endunless
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="6"><div class="empty-state"><span class="material-symbols-rounded">percent</span><p>No Tax Groups yet.</p></div></td></tr>
            @endforelse
        </tbody>
    </table>
</div>

@include('partials.pagination', ['paginator' => $taxGroups])
@endsection
