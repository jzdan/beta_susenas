<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['username', 'name', 'email', 'password', 'role', 'wilayah_scope', 'active'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    use Notifiable;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'active' => 'boolean',
        ];
    }

    public const ROLE_LABELS = [
        'admin' => 'Administrator',
        'pj' => 'Penanggung Jawab (PJ) Kecamatan',
        'pml' => 'Pengawas (PML) / Pengolah',
        'operator' => 'Operator Entri',
        'viewer' => 'Peninjau',
    ];

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function roleLabel(): string
    {
        return self::ROLE_LABELS[$this->role] ?? $this->role;
    }

    /** Inisial 1–2 huruf dari nama untuk avatar. */
    public function initials(): string
    {
        $parts = preg_split('/\s+/', trim(preg_replace('/[^\p{L}\p{N}\s]/u', ' ', (string) $this->name)));
        $parts = array_values(array_filter($parts));
        if (! $parts) {
            return strtoupper(substr((string) $this->username, 0, 2)) ?: '??';
        }
        $first = mb_substr($parts[0], 0, 1);
        $last = count($parts) > 1 ? mb_substr($parts[count($parts) - 1], 0, 1) : '';

        return mb_strtoupper($first.$last);
    }

    /** Warna avatar deterministik (hue 0–360) dari id/nama. */
    public function avatarHue(): int
    {
        return (int) (crc32($this->name.'|'.$this->id) % 360);
    }
}
