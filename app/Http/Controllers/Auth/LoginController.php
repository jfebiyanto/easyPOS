<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\PosSession;
use App\Models\Store;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class LoginController extends Controller
{
    /**
     * Hash dummy untuk menyamakan waktu respons saat username tidak ditemukan
     * (mencegah enumerasi akun lewat timing).
     */
    private const DUMMY_HASH = '$2y$12$M7SRwho8c6LaONdrf2ruyOHE4jYpyO6RvL.PUPGV.lR73wnoDR87m';

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

        $user = User::where('username', $credentials['username'])->first();
        $passwordValid = Hash::check($credentials['password'], $user?->password ?? self::DUMMY_HASH);

        if (! $user || ! $passwordValid || ! $user->is_active) {
            throw ValidationException::withMessages([
                'username' => 'Incorrect username or password, or the account is inactive.',
            ]);
        }

        Auth::login($user, $remember);

        $request->session()->regenerate();

        // Setiap login memulai ulang konfirmasi tanggal bisnis; kasir juga disapa sesuai jam login.
        $request->session()->forget('pos_backdate_ack');
        $request->session()->forget('pos_greeting');
        if ($user->isCashier()) {
            $request->session()->put('pos_greeting', login_greeting());
        }

        // Keep the active store in session for store-scoped master data.
        if (! $request->session()->has('active_store_id')) {
            $request->session()->put(
                'active_store_id',
                $user->store_id ?? Store::query()->orderBy('code')->value('id')
            );
        }

        // Admin (owner/manager) -> backoffice. Kasir -> halaman POS.
        if ($user->canAccessBackoffice()) {
            return redirect()->intended(route('admin.dashboard'));
        }

        return redirect()->intended(route('pos.index'));
    }

    public function logout(Request $request)
    {
        // Sesi POS yang aktif di browser ini berakhir bersama login-nya (sesi di perangkat lain tidak disentuh).
        if ($user = $request->user()) {
            $active = PosSession::activeFor($user);
            if ($active && $active->session_hash === PosSession::hashFor($request)) {
                $active->end(PosSession::ENDED_LOGOUT);
            }
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
