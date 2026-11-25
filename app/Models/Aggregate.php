<?php

namespace App\Models;

use App\Models\Concerns\GuardsLockedVersion;
use App\Models\Concerns\MarksRulesChanged;
use Illuminate\Database\Eloquent\Model;

class Aggregate extends Model
{
    use GuardsLockedVersion, MarksRulesChanged;

    public $timestamps = false;

    protected $fillable = [
        'qversion_id', 'name', 'label', 'block_code', 'value_var',
        'op', 'weight_ref', 'filter_var', 'filter_op', 'filter_val',
        'filter_ref', 'filter_ref_val', 'order',
    ];

    public function guardQversionId(): ?int
    {
        return $this->qversion_id;
    }
}
