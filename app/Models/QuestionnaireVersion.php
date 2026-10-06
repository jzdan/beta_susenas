<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class QuestionnaireVersion extends Model
{
    protected $fillable = ['cycle_id', 'code', 'effective_date', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

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

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class, 'qversion_id');
    }

    /** Versi yang ditandai aktif (global, 1 di seluruh sistem) — jatuh ke versi terbaru bila belum ada yang ditandai. */
    public static function active(): self
    {
        return static::where('is_active', true)->first()
            ?? static::orderByDesc('id')->firstOrFail();
    }

    /**
     * ID siklus yang boleh dibuka operator: siklus manapun yang PERIODENYA sama dengan
     * siklus milik versi yang sedang aktif — bukan cuma 1 siklus tunggal. Ini supaya
     * kuesioner yang dicacah bersamaan dalam satu periode (mis. KOR & KP triwulan yang
     * sama, tiap-tiap disimpan sebagai survey_cycle terpisah) tetap sama-sama terbuka
     * begitu salah satu ditandai aktif, bukan saling mengunci.
     *
     * @return array<int,int>
     */
    public static function activeCycleIds(): array
    {
        $period = static::active()->cycle?->period;

        return SurveyCycle::where('period', $period)->pluck('id')->all();
    }
}
