<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ValidationRule extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'qversion_id', 'code', 'scope', 'row_block', 'target_var', 'expr',
        'severity', 'message', 'active',
    ];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
