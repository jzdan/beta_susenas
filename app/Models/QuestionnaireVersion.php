<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class QuestionnaireVersion extends Model
{
    protected $fillable = ['cycle_id', 'code', 'effective_date'];

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(SurveyCycle::class, 'cycle_id');
    }

    public function blocks(): HasMany
    {
        return $this->hasMany(Block::class, 'qversion_id')->orderBy('order');
    }

    public function parameters(): HasMany
    {
        return $this->hasMany(Parameter::class, 'qversion_id');
    }

    public function derived(): HasMany
    {
        return $this->hasMany(DerivedField::class, 'qversion_id')->orderBy('order');
    }

    public function rules(): HasMany
    {
        return $this->hasMany(ValidationRule::class, 'qversion_id')->orderBy('code');
    }

    public function aggregates(): HasMany
    {
        return $this->hasMany(Aggregate::class, 'qversion_id')->orderBy('order');
    }
}
