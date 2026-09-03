<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    public $timestamps = false;

    protected $fillable = ['entity', 'entity_id', 'action', 'detail', 'by_user', 'at'];

    protected function casts(): array
    {
        return ['at' => 'datetime'];
    }

    public static function record(string $entity, $entityId, string $action, string $detail = '', ?int $byUser = null): void
    {
        static::create([
            'entity' => $entity,
            'entity_id' => (string) $entityId,
            'action' => $action,
            'detail' => $detail,
            'by_user' => $byUser ?? auth()->id(),
            'at' => now(),
        ]);
    }
}
