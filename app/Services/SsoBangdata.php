<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Klien OAuth2 untuk SSO Bangdata (sso.bangdata.web.id, server Laravel Passport): alur authorization
 * code, lalu data pengguna dari /api/user. Dipakai AuthController (tombol login) dan
 * SsoBangdataController (redirect + callback).
 */
class SsoBangdata
{
    public function aktif(): bool
    {
        return trim((string) config('services.bangdata.client_id')) !== '';
    }

    public function urlOtorisasi(string $state): string
    {
        $c = config('services.bangdata');

        return $c['url'].'/oauth/authorize?'.http_build_query([
            'client_id' => $c['client_id'],
            'redirect_uri' => $this->redirect(),
            'response_type' => 'code',
            'scope' => $c['scope'] ?? '',
            'state' => $state,
        ]);
    }

    /**
     * Tukar kode dengan token, lalu ambil data pengguna.
     *
     * @return array{sso_id: string, nama: string, username: ?string, email: ?string}
     */
    public function pengguna(string $kode): array
    {
        $c = config('services.bangdata');

        try {
            $token = Http::asForm()->acceptJson()->timeout(15)->post($c['url'].'/oauth/token', [
                'grant_type' => 'authorization_code',
                'client_id' => $c['client_id'],
                'client_secret' => $c['client_secret'],
                'redirect_uri' => $this->redirect(),
                'code' => $kode,
            ]);

            if (! $token->successful() || ! $token->json('access_token')) {
                throw new RuntimeException('SSO Bangdata menolak kode login ('.$token->status().').');
            }

            $user = Http::withToken($token->json('access_token'))->acceptJson()->timeout(15)->get($c['url'].'/api/user');
        } catch (ConnectionException) {
            throw new RuntimeException('Tidak bisa terhubung ke SSO Bangdata.');
        }

        if (! $user->successful()) {
            throw new RuntimeException('Data pengguna dari SSO Bangdata tidak bisa diambil ('.$user->status().').');
        }

        $d = (array) ($user->json('data') ?? $user->json());
        $id = $d['id'] ?? $d['uuid'] ?? null;

        if ($id === null || $id === '') {
            throw new RuntimeException('Data pengguna dari SSO Bangdata tidak memuat id.');
        }

        return [
            'sso_id' => (string) $id,
            'nama' => (string) ($d['name'] ?? $d['nama'] ?? $d['username'] ?? 'Pengguna SSO'),
            'username' => isset($d['username']) && $d['username'] !== '' ? (string) $d['username'] : null,
            'email' => isset($d['email']) && filter_var($d['email'], FILTER_VALIDATE_EMAIL) ? strtolower($d['email']) : null,
        ];
    }

    private function redirect(): string
    {
        return (string) (config('services.bangdata.redirect') ?: route('sso.bangdata.callback'));
    }
}
