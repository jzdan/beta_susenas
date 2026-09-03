<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Aggregate extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'qversion_id', 'name', 'label', 'block_code', 'value_var',
        'op', 'weight_ref', 'filter_var', 'filter_op', 'filter_val',
        'filter_ref', 'filter_ref_val', 'order',
    ];
}
