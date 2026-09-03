<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RuleResult extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'document_id', 'rule_id', 'status', 'message', 'target_var', 'row_index', 'computed_at',
    ];

    protected function casts(): array
    {
        return ['computed_at' => 'datetime'];
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(ValidationRule::class, 'rule_id');
    }
}
