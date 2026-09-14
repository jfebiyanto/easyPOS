<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Hash dummy (valid bcrypt) untuk menyamakan waktu respons saat username
     * tidak ditemukan, sehingga tidak bisa dipakai untuk enumerasi akun.
     */
    private const DUMMY_HASH = '$2y$12$M7SRwho8c6LaONdrf2ruyOHE4jYpyO6RvL.PUPGV.lR73wnoDR87m';

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);

        $user = \App\Models\User::where('username', $data['username'])->first();

        $passwordValid = Hash::check($data['password'], $user?->password ?? self::DUMMY_HASH);

        if (! $user || ! $passwordValid || ! $user->is_active) {
            throw ValidationException::withMessages([
                'username' => ['Username atau password salah, atau akun tidak aktif.'],
            ]);
        }

        $token = $user->createToken($data['device_name'] ?? 'api')->plainTextToken;

        return response()->json([
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => $this->userPayload($user),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user('sanctum')->currentAccessToken()->delete();

        return response()->json(['message' => 'Token dicabut.']);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user('sanctum');

        return response()->json([
            'user' => $this->userPayload($user),
        ]);
    }

    private function userPayload(\App\Models\User $user): array
    {
        return $user->only([
            'id', 'name', 'username', 'email', 'phone', 'role', 'is_active', 'store_id', 'company_id',
        ]);
    }
}