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
        'pj' => 'Penanggung Jawab Kecamatan',
        'pml' => 'Pengawas Lapangan / Pengolah',
        'operator' => 'Operator Entri',
        'viewer' => 'Peninjau',
    ];

    public function roleLabel(): string
    {
        return self::ROLE_LABELS[$this->role] ?? $this->role;
    }

    /** Warna avatar deterministik (hue 0–360) dari id/nama. */
    public function avatarHue(): int
    {
        return (int) (crc32($this->name.'|'.$this->id) % 360);
    }
}
