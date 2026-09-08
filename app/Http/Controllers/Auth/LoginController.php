<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class LoginController extends Controller
{
    public function showLoginForm()
    {
        return view('auth.login');
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ], [], ['username' => 'Username', 'password' => 'Password']);

        $remember = $request->boolean('remember');

        if (! Auth::attempt([
            'username' => $credentials['username'],
            'password' => $credentials['password'],
            'is_active' => true,
        ], $remember)) {
            throw ValidationException::withMessages([
                'username' => 'Username atau password salah, atau akun tidak aktif.',
            ]);
        }

        $request->session()->regenerate();

        $user = Auth::user();

        // Keep the active store in session for store-scoped master data.
        if (! $request->session()->has('active_store_id')) {
            $request->session()->put(
                'active_store_id',
                $user->store_id ?? \App\Models\Store::query()->orderBy('code')->value('id')
            );
        }

        // Admin -> backoffice. Cashier will later be routed to the POS module.
        return redirect()->intended(route('admin.dashboard'));
    }

    public function logout(Request $request)
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}