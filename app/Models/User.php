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
        'pml' => 'Pengawas (PML)',
        'editor' => 'Editor / Pengolah',
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
}
