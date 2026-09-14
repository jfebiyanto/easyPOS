<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use App\Support\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UserApiController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $this->ensureSuperuser($request);

        $query = User::where('company_id', $this->companyId($request));

        if ($storeId = $request->query('store_id')) {
            $query->where('store_id', $storeId);
        }
        if ($role = $request->query('role')) {
            $query->where('role', $role);
        }

        return response()->json(['data' => $query->get()]);
    }

    public function show(Request $request, User $user): JsonResponse
    {
        $this->ensureSuperuser($request);
        $this->authorizeCompany($request, $user->company_id);

        return response()->json(['data' => $user]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->ensureSuperuser($request);

        $data = $this->validated($request);
        $data['password'] = bcrypt($data['password']);
        $data['company_id'] = $this->companyId($request);
        $data['is_active'] = $request->boolean('is_active', true);

        $user = User::create($data);

        ActivityLogger::log('user.created', "Membuat user baru (API): {$user->username} ({$user->role})", $user);

        return response()->json(['data' => $user], 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $this->ensureSuperuser($request);
        $this->authorizeCompany($request, $user->company_id);

        $data = $this->validated($request, $user->id);

        if (! empty($data['password'])) {
            $data['password'] = bcrypt($data['password']);
        } else {
            unset($data['password']);
        }
        $data['is_active'] = $request->boolean('is_active');

        $this->guardLastOwner($user, $data);

        $user->update($data);

        ActivityLogger::log('user.updated', "Mengubah user (API): {$user->username}", $user);

        return response()->json(['data' => $user->fresh()]);
    }

    public function resetPassword(Request $request, User $user): JsonResponse
    {
        $this->ensureSuperuser($request);
        $this->authorizeCompany($request, $user->company_id);

        $data = $request->validate(['password' => ['required', 'string', Password::min(10)->letters()->numbers()]]);

        $user->update(['password' => $data['password']]);

        ActivityLogger::log('user.password_reset', "Reset password user (API): {$user->username}", $user);

        return response()->json(['message' => "Password {$user->username} berhasil direset."]);
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        $this->ensureSuperuser($request);
        $this->authorizeCompany($request, $user->company_id);

        if ($user->id === $request->user('sanctum')->id) {
            return response()->json(['message' => 'Tidak dapat menghapus akun sendiri.'], 422);
        }

        if ($user->isOwner() && $this->activeOwnerCount() <= 1) {
            return response()->json(['message' => 'Minimal harus ada 1 Owner aktif.'], 422);
        }

        ActivityLogger::log('user.deleted', "Menghapus user (API): {$user->username}", $user);

        $user->delete();

        return response()->json(['message' => 'User dihapus.']);
    }

    private function guardLastOwner(User $user, array $data): void
    {
        $willNotBeActiveOwner = $user->isOwner()
            && (($data['role'] ?? $user->role) !== User::ROLE_OWNER || ! ($data['is_active'] ?? $user->is_active));

        if ($willNotBeActiveOwner && $this->activeOwnerCount() <= 1) {
            abort(422, 'Minimal harus ada 1 Owner aktif.');
        }
    }

    private function activeOwnerCount(): int
    {
        return User::where('role', User::ROLE_OWNER)->where('is_active', true)->count();
    }

    private function validated(Request $request, ?string $id = null): array
    {
        return $request->validate([
            'store_id' => ['nullable', Rule::exists('stores', 'id')->where('company_id', $this->companyId($request))],
            'name' => ['required', 'string', 'max:150'],
            'username' => ['required', 'string', 'max:60', 'alpha_dash', Rule::unique('users', 'username')->ignore($id)],
            'email' => ['nullable', 'email', 'max:150', Rule::unique('users', 'email')->ignore($id)],
            'phone' => ['nullable', 'string', 'max:30'],
            'role' => ['required', Rule::in(User::ROLES)],
            'is_active' => ['sometimes', 'boolean'],
            'password' => [$id ? 'nullable' : 'required', 'string', Password::min(10)->letters()->numbers()],
        ]);
    }
}
