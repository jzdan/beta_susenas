<?php

namespace App\Models;

use App\Models\Concerns\GuardsLockedVersion;
use App\Models\Concerns\MarksRulesChanged;
use Illuminate\Database\Eloquent\Model;

class DerivedField extends Model
{
    use GuardsLockedVersion, MarksRulesChanged;

    public $timestamps = false;

    protected $fillable = ['qversion_id', 'var_name', 'label', 'expr', 'order'];

    public function guardQversionId(): ?int
    {
        return $this->qversion_id;
    }
}
