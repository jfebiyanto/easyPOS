@extends('layouts.admin')

@section('title', 'Promotions')
@section('page_title', 'Promotions')

@section('content')
<div class="table-toolbar">
    <h2 style="margin:0; font-size:16px;">Promotion List</h2>
    <div style="display:flex; gap:8px;">
        @if (auth()->user()->isOwner())
            <a href="{{ route('admin.promotion-hierarchy.edit') }}" class="btn btn-outline">
                <span class="material-symbols-rounded">low_priority</span> Promo Priority
            </a>
        @endif
        @unless ($readOnly)
        <a href="{{ route('admin.promotions.create') }}" class="btn btn-filled">
            <span class="material-symbols-rounded">add</span> Add Promotion
        </a>
        @endunless
    </div>
</div>

<div class="table-wrap">
    <table class="md-table">
        <thead>
            <tr><th>{!! sort_link('Kode', 'code') !!}</th><th>{!! sort_link('Nama', 'name') !!}</th><th>{!! sort_link('Tipe', 'type') !!}</th><th>Applies to</th><th>Rule</th><th>Period</th><th>{!! sort_link('Status', 'is_active') !!}</th><th style="text-align:right;">Actions</th></tr>
        </thead>
        <tbody>
            @forelse ($promotions as $promotion)
                <tr>
                    <td><strong>{{ $promotion->code }}</strong></td>
                    <td>{{ $promotion->name }}</td>
                    <td>
                        <span class="badge info">{{ \App\Models\Promotion::TYPE_LABELS[$promotion->type] ?? $promotion->type }}</span>
                        @if ($promotion->isFromSap()) <span class="badge active">SAP B1</span> @endif
                    </td>
                    <td>{{ $promotion->scopeLabel() }}</td>
                    <td>{{ $promotion->ruleSummary() }}</td>
                    <td>{{ $promotion->periodLabel() }}</td>
                    <td><span class="badge {{ $promotion->is_active ? 'active' : 'inactive' }}">{{ $promotion->is_active ? 'Aktif' : 'Nonaktif' }}</span></td>
                    <td>
                        @if ($promotion->isFromSap())
                            <div class="actions-cell"><span class="hint">Read-only</span></div>
                        @else
                            <div class="actions-cell">
                                @unless ($readOnly)
                                <a href="{{ route('admin.promotions.edit', $promotion) }}" class="btn btn-tonal btn-sm">Edit</a>
                                <form method="POST" action="{{ route('admin.promotions.destroy', $promotion) }}" onsubmit="return confirm('Hapus promosi ini?')">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                                </form>
                                @endunless
                            </div>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="8"><div class="empty-state"><span class="material-symbols-rounded">percent</span><p>No promotions yet.</p></div></td></tr>
            @endforelse
        </tbody>
    </table>
</div>

@include('partials.pagination', ['paginator' => $promotions])
@endsection