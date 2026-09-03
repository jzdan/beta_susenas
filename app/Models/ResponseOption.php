<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResponseOption extends Model
{
    public $timestamps = false;

    protected $fillable = ['question_id', 'code', 'label', 'order'];

    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }
}
