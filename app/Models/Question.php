<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Question extends Model
{
    protected $fillable = [
        'block_id', 'code_rincian', 'var_name', 'label', 'qtype',
        'unit', 'order', 'skip_expr', 'help_text',
    ];

    public function block(): BelongsTo
    {
        return $this->belongsTo(Block::class);
    }

    public function options(): HasMany
    {
        return $this->hasMany(ResponseOption::class)->orderBy('order');
    }
}
