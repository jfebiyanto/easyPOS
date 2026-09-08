<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use App\Support\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UserApiController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $query = User::query();

        if ($storeId = $request->query('store_id')) {
            $query->where('store_id', $storeId);
        }
        if ($type = $request->query('type')) {
            $query->where('type', $type);
        }

        return response()->json(['data' => $query->get()]);
    }

    public function show(User $user): JsonResponse
    {
        return response()->json(['data' => $user]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $data['password'] = bcrypt($data['password']);
        $data['is_superuser'] = $request->boolean('is_superuser');

        $user = User::create($data);

        ActivityLogger::log('user.created', "Membuat user baru (API): {$user->username} ({$user->type})", $user);

        return response()->json(['data' => $user], 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $data = $this->validated($request, $user->id);

        if (! empty($data['password'])) {
            $data['password'] = bcrypt($data['password']);
        } else {
            unset($data['password']);
        }

        $data['is_superuser'] = $request->boolean('is_superuser');
        $user->update($data);

        ActivityLogger::log('user.updated', "Mengubah user (API): {$user->username}", $user);

        return response()->json(['data' => $user->fresh()]);
    }

    public function resetPassword(Request $request, User $user): JsonResponse
    {
        $data = $request->validate(['password' => ['required', 'string', 'min:8']]);

        $user->update(['password' => $data['password']]);

        ActivityLogger::log('user.password_reset', "Reset password user (API): {$user->username}", $user);

        return response()->json(['message' => "Password {$user->username} berhasil direset."]);
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        if ($user->id === $request->user('sanctum')->id) {
            return response()->json(['message' => 'Tidak dapat menghapus akun sendiri.'], 422);
        }

        ActivityLogger::log('user.deleted', "Menghapus user (API): {$user->username}", $user);
        $user->delete();

        return response()->json(['message' => 'User dihapus.']);
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
            'is_active' => ['sometimes', 'boolean'],
            'password' => [$id ? 'nullable' : 'required', 'string', 'min:8'],
        ]);
    }
}