@extends('layouts.admin')

@section('title', 'Users')
@section('page_title', 'Daftar User')

@section('content')
<div class="table-toolbar">
    <h2 style="margin:0; font-size:16px;">Daftar User</h2>
    <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
        <form method="GET" action="{{ route('admin.users.index') }}" style="display:flex; gap:8px; align-items:center;">
            <select name="store" class="field" style="margin:0; padding:9px 12px; min-width:160px;" onchange="this.form.submit()">
                <option value="">Semua Store</option>
                @foreach ($stores as $store)
                    <option value="{{ $store->id }}" @selected(request('store') == $store->id)>{{ $store->code }} · {{ $store->name }}</option>
                @endforeach
            </select>
            <select name="role" class="field" style="margin:0; padding:9px 12px;" onchange="this.form.submit()">
                <option value="">Semua Role</option>
                @foreach ($roles as $role)
                    <option value="{{ $role }}" @selected(request('role') === $role)>{{ ucfirst(str_replace('_', ' ', $role)) }}</option>
                @endforeach
            </select>
        </form>
        <a href="{{ route('admin.users.create') }}" class="btn btn-filled">
            <span class="material-symbols-rounded">person_add</span> Tambah User
        </a>
    </div>
</div>

<div class="table-wrap">
    <table class="md-table">
        <thead>
            <tr>
                <th>{!! sort_link('Nama', 'name') !!}</th>
                <th>{!! sort_link('Username', 'username') !!}</th>
                <th>Store</th>
                <th>{!! sort_link('Role', 'role') !!}</th>
                <th>{!! sort_link('Status', 'is_active') !!}</th>
                <th style="text-align:right;">Aksi</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($users as $user)
                <tr>
                    <td>{{ $user->name }}</td>
                    <td><strong>{{ $user->username }}</strong></td>
                    <td>{{ $user->store ? $user->store->code.' · '.$user->store->name : '-' }}</td>
                    <td>
                        <span class="badge {{ $user->role }}">{{ ucfirst(str_replace('_', ' ', $user->role)) }}</span>
                    </td>
                    <td><span class="badge {{ $user->is_active ? 'active' : 'inactive' }}">{{ $user->is_active ? 'Aktif' : 'Nonaktif' }}</span></td>
                    <td>
                        <div class="actions-cell">
                            <a href="{{ route('admin.users.edit', $user) }}" class="btn btn-tonal btn-sm">Edit</a>
                            @if ($user->id !== auth()->id())
                                <form method="POST" action="{{ route('admin.users.destroy', $user) }}" onsubmit="return confirm('Hapus user ini?')">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="btn btn-danger btn-sm">Hapus</button>
                                </form>
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="6"><div class="empty-state"><span class="material-symbols-rounded">group_off</span><p>Belum ada user.</p></div></td></tr>
            @endforelse
        </tbody>
    </table>
</div>

@include('partials.pagination', ['paginator' => $users])
@endsection