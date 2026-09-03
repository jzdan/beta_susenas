<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DerivedField extends Model
{
    public $timestamps = false;

    protected $fillable = ['qversion_id', 'var_name', 'label', 'expr', 'order'];
}
