<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SurveyCycle extends Model
{
    protected $fillable = ['name', 'period', 'status', 'field_start', 'field_end'];

    protected $casts = [
        'field_start' => 'date',
        'field_end' => 'date',
    ];

    /** Siklus 'selesai' terkunci: entri & pengolahan tidak boleh mengubah data lagi. */
    public function isLocked(): bool
    {
        return $this->status === 'selesai';
    }

    /**
     * Periode lapangan dibuka: petugas boleh membuat & mengisi isian. Diatur admin per siklus
     * di Jadwal Lapangan — terpisah dari "versi aktif" (itu cuma versi bawaan menu admin).
     */
    public function isFieldOpen(): bool
    {
        return $this->status === 'lapangan';
    }

    public function versions(): HasMany
    {
        return $this->hasMany(QuestionnaireVersion::class, 'cycle_id');
    }

    public function samples(): HasMany
    {
        return $this->hasMany(SampleHousehold::class, 'cycle_id');
    }
}
