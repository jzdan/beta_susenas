<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AnswerHistory extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'document_id', 'question_id', 'art_index', 'old_value', 'new_value',
        'by_user', 'reason', 'at',
    ];

    protected function casts(): array
    {
        return ['at' => 'datetime'];
    }
}
