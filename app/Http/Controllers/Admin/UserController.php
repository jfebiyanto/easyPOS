<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\AuthorizesSuperuser;
use App\Http\Controllers\Concerns\AuthorizesCompany;
use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Models\User;
use App\Support\ActivityLogger;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class UserController extends Controller
{
    use AuthorizesCompany, AuthorizesSuperuser;

    public function index(Request $request): View
    {
        $this->ensureSuperuser($request);

        $query = User::with('store');

        if ($storeId = $request->query('store')) {
            $query->where('store_id', $storeId);
        }
        if ($role = $request->query('role')) {
            $query->where('role', $role);
        }

        $query = apply_sort(
            $query,
            $request->query('sort'), $request->query('dir'),
            ['name', 'username', 'role', 'is_active', 'created_at'],
            'created_at'
        );

        return view('admin.users.index', [
            'users' => $query->paginate(10)->withQueryString(),
            'stores' => Store::orderBy('code')->get(),
            'roles' => User::ROLES,
        ]);
    }

    public function create(Request $request): View
    {
        $this->ensureSuperuser($request);

        return view('admin.users.form', [
            'user' => new User,
            'stores' => $this->assignableStores(),
            'roles' => User::ROLES,
        ]);
    }

    public function store(Request $request)
    {
        $this->ensureSuperuser($request);

        $data = $this->validated($request);
        $data['password'] = bcrypt($data['password'] ?? '');
        $data['company_id'] = current_company_id();
        $data['is_active'] = $request->boolean('is_active', true);

        $user = User::create($data);

        ActivityLogger::log('user.created', "Membuat user baru: {$user->username} ({$user->role})", $user, [
            'role' => $user->role, 'store_id' => $user->store_id,
        ]);

        return redirect()->route('admin.users.index')->with('success', 'User berhasil dibuat.');
    }

    public function edit(Request $request, User $user): View
    {
        $this->ensureSuperuser($request);
        $this->ensureCompany($user->company_id);

        return view('admin.users.form', [
            'user' => $user,
            'stores' => $this->assignableStores($user),
            'roles' => User::ROLES,
        ]);
    }

    public function update(Request $request, User $user)
    {
        $this->ensureSuperuser($request);
        $this->ensureCompany($user->company_id);

        $data = $this->validated($request, $user->id);

        if (! empty($data['password'])) {
            $data['password'] = bcrypt($data['password']);
        } else {
            unset($data['password']);
        }
        $data['is_active'] = $request->boolean('is_active');

        // Cegah lockout: minimal 1 Owner aktif.
        $this->guardLastOwner($user, $data);

        $previousStoreId = $user->store_id;

        $user->update($data);

        ActivityLogger::log('user.updated', "Mengubah user: {$user->username}", $user, [
            'role' => $user->role,
            'password_changed' => ! empty($data['password']),
        ]);

        if ($user->store_id !== $previousStoreId) {
            $previousStoreCode = Store::whereKey($previousStoreId)->value('code') ?? '-';
            $newStoreCode = Store::whereKey($user->store_id)->value('code') ?? '-';

            ActivityLogger::log('user.store_changed', "Memindahkan {$user->username} dari gerai {$previousStoreCode} ke {$newStoreCode}", $user, [
                'from_store_id' => $previousStoreId,
                'to_store_id' => $user->store_id,
            ]);
        }

        return redirect()->route('admin.users.index')->with('success', 'User berhasil diperbarui.');
    }

    public function resetPassword(Request $request, User $user)
    {
        $this->ensureSuperuser($request);
        $this->ensureCompany($user->company_id);

        $data = $request->validate([
            'password' => ['required', 'confirmed', Password::min(10)->letters()->numbers()],
        ], [], ['password' => 'Password baru']);

        $user->update(['password' => $data['password']]);

        ActivityLogger::log('user.password_reset', "Reset password user: {$user->username}", $user);

        return redirect()->route('admin.users.edit', $user)->with('success', "Password {$user->username} berhasil direset.");
    }

    public function destroy(Request $request, User $user)
    {
        $this->ensureSuperuser($request);
        $this->ensureCompany($user->company_id);

        if ($user->id === auth()->id()) {
            return back()->with('error', 'Anda tidak dapat menghapus akun sendiri.');
        }

        if ($user->isOwner() && $this->activeOwnerCount() <= 1) {
            return back()->with('error', 'Minimal harus ada 1 Owner aktif.');
        }

        ActivityLogger::log('user.deleted', "Menghapus user: {$user->username} ({$user->name})", $user);

        $user->delete();

        return redirect()->route('admin.users.index')->with('success', 'User berhasil dihapus.');
    }

    private function guardLastOwner(User $user, array $data): void
    {
        $willNotBeActiveOwner = $user->isOwner()
            && (($data['role'] ?? $user->role) !== User::ROLE_OWNER || ! ($data['is_active'] ?? $user->is_active));

        if ($willNotBeActiveOwner && $this->activeOwnerCount() <= 1) {
            abort(422, 'Minimal harus ada 1 Owner aktif. Perubahan ditolak.');
        }
    }

    private function activeOwnerCount(): int
    {
        return User::where('role', User::ROLE_OWNER)->where('is_active', true)->count();
    }

    /**
     * Gerai aktif, ditambah gerai user saat ini (walau nonaktif) agar penugasannya tetap terlihat di form.
     */
    private function assignableStores(?User $user = null): Collection
    {
        return Store::where(fn ($query) => $query->where('is_active', true)->orWhere('id', $user?->store_id))
            ->orderBy('name')
            ->get();
    }

    private function validated(Request $request, ?string $id = null): array
    {
        $requiresActiveStore = $request->input('role') !== User::ROLE_OWNER;

        $storeExists = Rule::exists('stores', 'id')->where('company_id', current_company_id());
        if ($requiresActiveStore) {
            $storeExists->where('is_active', true);
        }

        return $request->validate([
            'store_id' => [Rule::requiredIf($requiresActiveStore), 'nullable', $storeExists],
            'name' => ['required', 'string', 'max:150'],
            'username' => ['required', 'string', 'max:60', 'alpha_dash', Rule::unique('users', 'username')->ignore($id)],
            'email' => ['nullable', 'email', 'max:150', Rule::unique('users', 'email')->ignore($id)],
            'phone' => ['nullable', 'string', 'max:30'],
            'role' => ['required', Rule::in(User::ROLES)],
            'is_active' => ['sometimes', 'boolean'],
            'password' => [$id ? 'nullable' : 'required', 'string', Password::min(10)->letters()->numbers()],
        ], [
            'store_id.required' => 'Kasir dan Store Manager wajib ditugaskan ke satu gerai aktif.',
            'store_id.exists' => 'Gerai yang dipilih tidak aktif atau tidak tersedia.',
        ]);
    }
}
