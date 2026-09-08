<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);

        $user = \App\Models\User::where('username', $data['username'])->first();

        if (! $user || ! Auth::validate(['username' => $data['username'], 'password' => $data['password']]) || ! $user->is_active) {
            throw ValidationException::withMessages([
                'username' => ['Username atau password salah, atau akun tidak aktif.'],
            ]);
        }

        $token = $user->createToken($data['device_name'] ?? 'api')->plainTextToken;

        return response()->json([
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => $user->only(['id', 'name', 'username', 'email', 'type', 'is_superuser', 'store_id']),
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
            'user' => $user->only(['id', 'name', 'username', 'email', 'type', 'is_superuser', 'store_id']),
            'is_superuser' => $user->isSuperuser(),
        ]);
    }
}