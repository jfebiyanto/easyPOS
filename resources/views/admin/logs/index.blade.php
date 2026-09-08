@extends('layouts.admin')

@section('title', 'Log Aktivitas')
@section('page_title', 'Log Aktivitas')

@section('content')
<div class="table-toolbar">
    <h2 style="margin:0; font-size:16px;">Riwayat Perubahan</h2>
    <form method="GET" action="{{ route('admin.logs.index') }}" style="display:flex; gap:8px;">
        <input type="text" name="q" placeholder="Cari deskripsi atau aktor..." value="{{ request('q') }}" style="padding:9px 12px; border:1px solid var(--md-outline); border-radius:8px; min-width:260px;">
        <select name="action" onchange="this.form.submit()" style="padding:9px 12px; border:1px solid var(--md-outline); border-radius:8px;">
            <option value="">Semua Aksi</option>
            @foreach (['user.created', 'user.updated', 'user.deleted', 'user.password_reset', 'store.created', 'store.updated', 'store.deleted', 'store.switched', 'product_group.created', 'product_group.updated', 'product_group.deleted', 'price_list.created', 'price_list.updated', 'price_list.deleted', 'price_list.item_changed', 'price_list.item_deleted', 'product.created', 'product.updated', 'product.deleted', 'brand.created', 'brand.updated', 'brand.deleted', 'promotion.created', 'promotion.updated', 'promotion.deleted'] as $a)
                <option value="{{ $a }}" @selected(request('action') === $a)>{{ $a }}</option>
            @endforeach
        </select>
        <button class="btn btn-tonal" type="submit"><span class="material-symbols-rounded">search</span></button>
    </form>
</div>

<div class="table-wrap">
    <table class="md-table">
        <thead>
            <tr><th>{!! sort_link('Waktu', 'created_at') !!}</th><th>{!! sort_link('Oleh', 'actor_name') !!}</th><th>{!! sort_link('Aksi', 'action') !!}</th><th>Deskripsi</th><th>Objek</th></tr>
        </thead>
        <tbody>
            @forelse ($logs as $log)
                <tr>
                    <td style="white-space: nowrap;">{{ $log->created_at->format('d/m/Y H:i:s') }}</td>
                    <td><strong>{{ $log->actor_name }}</strong></td>
                    <td><span class="badge info">{{ $log->action }}</span></td>
                    <td>{{ $log->description }}</td>
                    <td>{{ $log->subject_type ?? '-' }}<br><span style="font-size:11px; color:var(--md-on-surface-variant);">{{ $log->subject_id }}</span></td>
                </tr>
            @empty
                <tr><td colspan="5"><div class="empty-state"><span class="material-symbols-rounded">history</span><p>Belum ada aktivitas tercatat.</p></div></td></tr>
            @endforelse
        </tbody>
    </table>
</div>

@include('partials.pagination', ['paginator' => $logs])
@endsection