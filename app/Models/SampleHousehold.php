<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SampleHousehold extends Model
{
    protected $fillable = [
        'cycle_id', 'wilayah_code', 'no_bs', 'no_ruta', 'krt_name',
        'petugas_id', 'pml_id', 'status', 'weight',
        'nonresponse_reason', 'status_note', 'replaces_id',
    ];

    protected $casts = ['weight' => 'float'];

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class, 'sample_id');
    }

    /** Sampel asal yang digantikan oleh sampel ini (bila ini pengganti). */
    public function replaces(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaces_id');
    }

    /** Sampel pengganti untuk sampel ini (bila sampel ini diganti). */
    public function replacedBy(): HasMany
    {
        return $this->hasMany(self::class, 'replaces_id');
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(SurveyCycle::class, 'cycle_id');
    }

    public function petugas(): BelongsTo
    {
        return $this->belongsTo(User::class, 'petugas_id');
    }

    public function pml(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pml_id');
    }
}
