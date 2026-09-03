<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DocumentSnapshot extends Model
{
    public $timestamps = false;

    protected $fillable = ['document_id', 'data', 'updated_at'];

    protected function casts(): array
    {
        return ['data' => 'array', 'updated_at' => 'datetime'];
    }
}
