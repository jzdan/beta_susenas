<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Services\SsoBangdata;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function showLogin(Request $request, SsoBangdata $sso)
    {
        if ($request->user()) {
            return redirect($this->safeNext($request->query('next')));
        }
        return view('auth.login', [
            'next' => $this->safeNext($request->query('next')),
            'ssoAktif' => $sso->aktif(),
        ]);
    }

    /** Penguncian sementara akun setelah beberapa kali gagal. */
    private const LOCK_MINUTES = 15;

    private const LOCK_THRESHOLD = 5;

    public function login(Request $request, SsoBangdata $sso)
    {
        abort_if($sso->aktif(), 404);

        $data = $request->validate([
            'username' => 'required|string',
            'password' => 'required|string',
        ]);
        $next = $this->safeNext($request->input('next'));
        $username = $data['username'];
        $ip = $request->ip();

        // penguncian sementara: kegagalan dalam LOCK_MINUTES terakhir, sejak login
        // sukses terakhir untuk username itu (login sukses me-reset hitungan).
        $userId = \App\Models\User::where('username', $username)->value('id');
        $lastOk = $userId
            ? AuditLog::where('entity', 'auth')->where('action', 'login')->where('by_user', $userId)->max('at')
            : null;
        $sinceFails = AuditLog::where('entity', 'auth')->where('entity_id', $username)
            ->where('action', 'login-gagal')
            ->where('at', '>=', now()->subMinutes(self::LOCK_MINUTES));
        if ($lastOk) {
            $sinceFails->where('at', '>', $lastOk);
        }
        $recentFails = $sinceFails->count();
        if ($recentFails >= self::LOCK_THRESHOLD) {
            AuditLog::record('auth', $username, 'login-terkunci', "ip={$ip}");
            throw ValidationException::withMessages([
                'username' => 'Akun terkunci sementara karena terlalu banyak percobaan gagal. Coba lagi dalam '
                    .self::LOCK_MINUTES.' menit atau hubungi admin.',
            ]);
        }

        if (! Auth::attempt(['username' => $username, 'password' => $data['password'], 'active' => true],
            $request->boolean('remember'))) {
            AuditLog::record('auth', $username, 'login-gagal', "ip={$ip}");
            throw ValidationException::withMessages([
                'username' => 'Username atau kata sandi salah.',
            ]);
        }

        $request->session()->regenerate();
        AuditLog::record('auth', $request->user()->id, 'login', "ip={$ip}", $request->user()->id);

        return redirect($next);
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect()->route('login');
    }

    private function safeNext(?string $next): string
    {
        if (! $next || ! str_starts_with($next, '/') || str_starts_with($next, '//')) {
            return route('monitoring.dashboard');
        }
        return $next;
    }
}
