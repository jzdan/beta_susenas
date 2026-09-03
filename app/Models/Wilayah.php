<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Wilayah extends Model
{
    public $timestamps = false;

    protected $table = 'wilayah';

    protected $fillable = ['code', 'name', 'level', 'parent_code'];
}
