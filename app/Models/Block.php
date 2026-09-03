<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Block extends Model
{
    protected $fillable = ['qversion_id', 'code', 'title', 'order', 'repeat'];

    public function qversion(): BelongsTo
    {
        return $this->belongsTo(QuestionnaireVersion::class, 'qversion_id');
    }

    public function questions(): HasMany
    {
        return $this->hasMany(Question::class)->orderBy('order');
    }

    public function rows(): HasMany
    {
        return $this->hasMany(BlockRow::class)->orderBy('order');
    }
}
