<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\SsoBangdata;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Login lewat SSO Bangdata. Akun Sistem SUSENAS tetap dikelola admin di /admin/pengguna (peran &
 * wilayah_scope per petugas) — SSO di sini HANYA mengautentikasi; tidak pernah membuat akun baru,
 * agar peran/wilayah tidak pernah ditebak dari profil SSO. Pencocokan ke akun lokal lewat username.
 */
class SsoBangdataController extends Controller
{
    public function redirect(Request $request, SsoBangdata $sso)
    {
        abort_unless($sso->aktif(), 404);

        $state = Str::random(40);
        $request->session()->put('sso_bangdata_state', $state);

        return redirect()->away($sso->urlOtorisasi($state));
    }

    public function callback(Request $request, SsoBangdata $sso)
    {
        abort_unless($sso->aktif(), 404);

        $state = (string) $request->session()->pull('sso_bangdata_state', '');
        $gagal = fn (string $pesan) => redirect()->route('login')->withErrors(['sso' => $pesan]);

        if ($state === '' || ! hash_equals($state, (string) $request->query('state'))) {
            return $gagal('Sesi login SSO tidak cocok. Silakan coba masuk lagi.');
        }

        if ($request->filled('error')) {
            return $gagal('Login SSO dibatalkan atau ditolak.');
        }

        if (! $request->filled('code')) {
            return $gagal('Login SSO tidak mengembalikan kode.');
        }

        try {
            $data = $sso->pengguna((string) $request->query('code'));
        } catch (RuntimeException $e) {
            Log::warning('Login SSO Bangdata gagal', ['pesan' => $e->getMessage()]);

            return $gagal($e->getMessage());
        }

        $user = User::where('sso_id', $data['sso_id'])->first();

        if (! $user && $data['username']) {
            $user = User::whereRaw('LOWER(username) = ?', [strtolower($data['username'])])
                ->whereNull('sso_id')
                ->first();
        }

        if (! $user) {
            AuditLog::record('auth', $data['username'] ?? $data['sso_id'], 'login-sso-tidak-dikenal', 'sso_id='.$data['sso_id']);

            return $gagal('Akun SSO Anda belum terdaftar di Sistem SUSENAS. Hubungi admin untuk didaftarkan.');
        }

        if (! $user->active) {
            return $gagal('Akun Anda di Sistem SUSENAS dinonaktifkan. Hubungi admin.');
        }

        if ($user->sso_id !== $data['sso_id']) {
            $user->forceFill(['sso_id' => $data['sso_id']])->save();
        }

        Auth::login($user);
        $request->session()->regenerate();
        AuditLog::record('auth', $user->id, 'login-sso', 'sso_id='.$data['sso_id'], $user->id);

        return redirect()->intended(route('monitoring.dashboard'));
    }
}
