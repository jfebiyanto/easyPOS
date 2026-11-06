@extends('layouts.admin')

@section('title', $user->exists ? 'Edit User' : 'Add User')
@section('page_title', $user->exists ? 'Edit User' : 'Add User')

@section('content')
<div class="card">
    <h2 class="card-title">{{ $user->exists ? 'Edit User' : 'New User' }}</h2>
    <p class="card-subtitle">
        <strong>Cashier</strong> can only log in to the POS module.
        <strong>Store Manager</strong> manages their own store (products, prices, stock, customers, EOD).
        <strong>Owner</strong> has the highest access, including company settings, stores and users.
    </p>

    <form method="POST" action="{{ $user->exists ? route('admin.users.update', $user) : route('admin.users.store') }}">
        @csrf
        @if ($user->exists) @method('PUT') @endif

        <div class="form-grid">
            <div class="field @error('name') has-error @enderror">
                <label for="name">Full Name *</label>
                <input type="text" id="name" name="name" value="{{ old('name', $user->name) }}" required>
                @error('name') <div class="error">{{ $message }}</div> @enderror
            </div>
            <div class="field @error('username') has-error @enderror">
                <label for="username">Username * (used to log in)</label>
                <input type="text" id="username" name="username" value="{{ old('username', $user->username) }}" required maxlength="60">
                @error('username') <div class="error">{{ $message }}</div> @enderror
            </div>
            <div class="field @error('email') has-error @enderror">
                <label for="email">Email (for sending email)</label>
                <input type="email" id="email" name="email" value="{{ old('email', $user->email) }}">
                @error('email') <div class="error">{{ $message }}</div> @enderror
            </div>
            <div class="field @error('phone') has-error @enderror">
                <label for="phone">Phone</label>
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
                    <option value="">- Select Store -</option>
                    @foreach ($stores as $store)
                        <option value="{{ $store->id }}" @selected(old('store_id', $user->store_id) == $store->id)>{{ $store->code }} · {{ $store->name }}{{ $store->is_active ? '' : ' (nonaktif)' }}</option>
                    @endforeach
                </select>
                <div class="hint">Required for Cashiers and Store Managers: one active store. The Owner moves a user to another store on this page.</div>
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
                    <label for="is_active">User active</label>
                </div>
            </div>
            <div class="field" style="grid-column: 1 / -1;">
                <div class="hint">
                    There must always be at least <strong>1 active Owner</strong>. The system rejects changes that would leave no active Owner.
                </div>
            </div>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-filled"><span class="material-symbols-rounded">save</span> Save</button>
            <a href="{{ route('admin.users.index') }}" class="btn btn-outline">Cancel</a>
        </div>
    </form>
</div>

@if ($user->exists)
    <div class="card" id="reset-password">
        <h2 class="card-title">Reset Password</h2>
        <p class="card-subtitle">
            Reset password for user <strong>{{ $user->username }}</strong>.
            The new password must be at least 8 characters and the action will be recorded in the <a href="{{ route('admin.logs.index') }}">Activity Log</a>.
        </p>

        @if ($errors->has('password'))
            <div class="md-snackbar error"><span class="material-symbols-rounded">error</span>{{ $errors->first('password') }}</div>
        @endif

        <form method="POST" action="{{ route('admin.users.reset-password', $user) }}">
            @csrf
            <div class="form-grid">
                <div class="field @error('password') has-error @enderror">
                    <label for="reset_password">New Password *</label>
                    <input type="password" id="reset_password" name="password" required minlength="8" autocomplete="new-password">
                    @error('password') <div class="error">{{ $message }}</div> @enderror
                </div>
                <div class="field @error('password') has-error @enderror">
                    <label for="reset_password_confirmation">Repeat New Password *</label>
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