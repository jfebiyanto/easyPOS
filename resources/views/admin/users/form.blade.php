@extends('layouts.admin')

@section('title', $user->exists ? 'Edit User' : 'Tambah User')
@section('page_title', $user->exists ? 'Edit User' : 'Tambah User')

@section('content')
<div class="card">
    <h2 class="card-title">{{ $user->exists ? 'Ubah User' : 'User Baru' }}</h2>
    <p class="card-subtitle">
        <strong>Kasir</strong> hanya bisa login ke modul POS.
        <strong>Store Manager</strong> mengelola gerainya (produk, harga, stok, customer, EOD).
        <strong>Owner</strong> memiliki akses tertinggi termasuk setting perusahaan, gerai, dan user.
    </p>

    <form method="POST" action="{{ $user->exists ? route('admin.users.update', $user) : route('admin.users.store') }}">
        @csrf
        @if ($user->exists) @method('PUT') @endif

        <div class="form-grid">
            <div class="field @error('name') has-error @enderror">
                <label for="name">Nama Lengkap *</label>
                <input type="text" id="name" name="name" value="{{ old('name', $user->name) }}" required>
                @error('name') <div class="error">{{ $message }}</div> @enderror
            </div>
            <div class="field @error('username') has-error @enderror">
                <label for="username">Username * (dipakai untuk login)</label>
                <input type="text" id="username" name="username" value="{{ old('username', $user->username) }}" required maxlength="60">
                @error('username') <div class="error">{{ $message }}</div> @enderror
            </div>
            <div class="field @error('email') has-error @enderror">
                <label for="email">Email (untuk pengiriman email)</label>
                <input type="email" id="email" name="email" value="{{ old('email', $user->email) }}">
                @error('email') <div class="error">{{ $message }}</div> @enderror
            </div>
            <div class="field @error('phone') has-error @enderror">
                <label for="phone">Telepon</label>
                <input type="text" id="phone" name="phone" value="{{ old('phone', $user->phone) }}" maxlength="30">
                @error('phone') <div class="error">{{ $message }}</div> @enderror
            </div>
            <div class="field @error('role') has-error @enderror">
                <label for="role">Role *</label>
                <select id="role" name="role">
                    @foreach ($roles as $role)
                        <option value="{{ $role }}" @selected(old('role', $user->role ?? 'cashier') === $role)>
                            {{ ['owner' => 'Owner (akses penuh perusahaan)', 'store_manager' => 'Store Manager (kelola gerai)', 'cashier' => 'Kasir (modul POS saja)'][$role] ?? $role }}
                        </option>
                    @endforeach
                </select>
                @error('role') <div class="error">{{ $message }}</div> @enderror
            </div>
            <div class="field @error('store_id') has-error @enderror">
                <label for="store_id">Store</label>
                <select id="store_id" name="store_id">
                    <option value="">- Pilih Store -</option>
                    @foreach ($stores as $store)
                        <option value="{{ $store->id }}" @selected(old('store_id', $user->store_id) == $store->id)>{{ $store->code }} · {{ $store->name }}</option>
                    @endforeach
                </select>
                @error('store_id') <div class="error">{{ $message }}</div> @enderror
            </div>
            <div class="field @error('password') has-error @enderror">
                <label for="password">Password {{ $user->exists ? '(kosongkan jika tidak diubah)' : '*' }}</label>
                <input type="password" id="password" name="password" @unless($user->exists) required @endunless minlength="8">
                @error('password') <div class="error">{{ $message }}</div> @enderror
            </div>
            <div class="field">
                <div class="checkbox-row">
                    <input type="checkbox" id="is_active" name="is_active" value="1" @checked(old('is_active', $user->exists ? $user->is_active : true))>
                    <label for="is_active">User aktif</label>
                </div>
            </div>
            <div class="field" style="grid-column: 1 / -1;">
                <div class="hint">
                    Minimal harus selalu ada <strong>1 Owner aktif</strong>. Sistem akan menolak perubahan yang membuat tidak ada Owner aktif.
                </div>
            </div>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-filled"><span class="material-symbols-rounded">save</span> Simpan</button>
            <a href="{{ route('admin.users.index') }}" class="btn btn-outline">Batal</a>
        </div>
    </form>
</div>

@if ($user->exists)
    <div class="card" id="reset-password">
        <h2 class="card-title">Reset Password</h2>
        <p class="card-subtitle">
            Reset password untuk user <strong>{{ $user->username }}</strong>.
            Password baru wajib minimal 8 karakter dan akan tercatat di <a href="{{ route('admin.logs.index') }}">Log Aktivitas</a>.
        </p>

        @if ($errors->has('password'))
            <div class="md-snackbar error"><span class="material-symbols-rounded">error</span>{{ $errors->first('password') }}</div>
        @endif

        <form method="POST" action="{{ route('admin.users.reset-password', $user) }}">
            @csrf
            <div class="form-grid">
                <div class="field @error('password') has-error @enderror">
                    <label for="reset_password">Password Baru *</label>
                    <input type="password" id="reset_password" name="password" required minlength="8" autocomplete="new-password">
                    @error('password') <div class="error">{{ $message }}</div> @enderror
                </div>
                <div class="field @error('password') has-error @enderror">
                    <label for="reset_password_confirmation">Ulangi Password Baru *</label>
                    <input type="password" id="reset_password_confirmation" name="password_confirmation" required minlength="8" autocomplete="new-password">
                    @error('password') <div class="error">{{ $message }}</div> @enderror
                </div>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn-filled">
                    <span class="material-symbols-rounded">key</span> Reset Password
                </button>
            </div>
        </form>
    </div>
@endif
@endsection