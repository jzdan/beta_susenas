<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VisitPhoto extends Model
{
    protected $fillable = ['sample_id', 'path', 'caption', 'uploaded_by', 'bytes'];

    public function sample(): BelongsTo
    {
        return $this->belongsTo(SampleHousehold::class, 'sample_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
