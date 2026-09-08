<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Models\User;
use App\Support\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class UserController extends Controller
{
    public function index(Request $request): View
    {
        $query = User::with('store');

        if ($storeId = $request->query('store')) {
            $query->where('store_id', $storeId);
        }

        if ($type = $request->query('type')) {
            $query->where('type', $type);
        }

        $query = apply_sort(
            $query,
            $request->query('sort'), $request->query('dir'),
            ['name', 'username', 'type', 'is_active', 'created_at'],
            'created_at'
        );

        return view('admin.users.index', [
            'users' => $query->paginate(10)->withQueryString(),
            'stores' => Store::orderBy('code')->get(),
        ]);
    }

    public function create(): View
    {
        return view('admin.users.form', [
            'user' => new User(),
            'stores' => Store::orderBy('name')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);

        $data['password'] = bcrypt($data['password'] ?? '');
        $data['is_superuser'] = $request->boolean('is_superuser');

        $user = User::create($data);

        ActivityLogger::log('user.created', "Membuat user baru: {$user->username} ({$user->type})", $user, [
            'type' => $user->type, 'is_superuser' => $user->is_superuser, 'store_id' => $user->store_id,
        ]);

        return redirect()->route('admin.users.index')->with('success', 'User berhasil dibuat.');
    }

    public function edit(User $user): View
    {
        return view('admin.users.form', [
            'user' => $user,
            'stores' => Store::orderBy('name')->get(),
        ]);
    }

    public function update(Request $request, User $user)
    {
        $data = $this->validated($request, $user->id);

        if (! empty($data['password'])) {
            $data['password'] = bcrypt($data['password']);
        } else {
            unset($data['password']);
        }

        $data['is_superuser'] = $request->boolean('is_superuser');

        $changes = array_diff_assoc(
            collect($data)->except('password')->all(),
            collect($user->only(array_keys($data)))->except('password')->all()
        );
        $user->update($data);

        ActivityLogger::log('user.updated', "Mengubah user: {$user->username}", $user, [
            'changes' => array_keys($changes),
            'password_changed' => ! empty($data['password']),
        ]);

        return redirect()->route('admin.users.index')->with('success', 'User berhasil diperbarui.');
    }

    /**
     * Reset password dipisah dari update profil: hanya bisa dikirim
     * dari halaman edit user (tombol di index mengarah ke halaman ini).
     */
    public function resetPassword(Request $request, User $user)
    {
        $data = $request->validate([
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ], [], ['password' => 'Password baru']);

        $user->update(['password' => $data['password']]);

        ActivityLogger::log('user.password_reset', "Reset password user: {$user->username}", $user);

        return redirect()->route('admin.users.edit', $user)->with('success', "Password {$user->username} berhasil direset.");
    }

    public function destroy(User $user)
    {
        if ($user->id === auth()->id()) {
            return back()->with('error', 'Anda tidak dapat menghapus akun sendiri.');
        }

        ActivityLogger::log('user.deleted', "Menghapus user: {$user->username} ({$user->name})", $user);

        $user->delete();

        return redirect()->route('admin.users.index')->with('success', 'User berhasil dihapus.');
    }

    private function validated(Request $request, ?string $id = null): array
    {
        return $request->validate([
            'store_id' => ['nullable', 'exists:stores,id'],
            'name' => ['required', 'string', 'max:150'],
            'username' => ['required', 'string', 'max:60', 'alpha_dash', Rule::unique('users', 'username')->ignore($id)],
            'email' => ['nullable', 'email', 'max:150', Rule::unique('users', 'email')->ignore($id)],
            'phone' => ['nullable', 'string', 'max:30'],
            'type' => ['required', Rule::in(User::TYPES)],
            'is_superuser' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'password' => [$id ? 'nullable' : 'required', 'string', 'min:8'],
        ]);
    }
}