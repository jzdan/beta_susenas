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
        return ['is_active' => 'boolean', 'rules_changed_at' => 'datetime'];
    }

    /** Tandai bahan pemeriksaan versi ini berubah (Inc 153, lihat Concerns\MarksRulesChanged). */
    public static function markRulesChanged(?int $qvId): void
    {
        if ($qvId) {
            \Illuminate\Support\Facades\DB::table('questionnaire_versions')->where('id', $qvId)
                ->update(['rules_changed_at' => now()->format('Y-m-d H:i:s')]);
        }
    }

    /**
     * Isian versi ini yang hasil pemeriksaannya lebih tua dari perubahan aturan terakhir
     * (atau belum pernah diperiksa). Siklus yang sudah dikunci dilewati.
     */
    public function staleDocuments(): \Illuminate\Database\Eloquent\Builder
    {
        $since = $this->rules_changed_at?->format('Y-m-d H:i:s');

        return Document::query()->select('documents.*')
            ->where('documents.qversion_id', $this->id)
            ->whereHas('sample.cycle', fn ($c) => $c->where('status', '!=', 'selesai'))
            ->leftJoin('document_snapshots as ds', 'ds.document_id', '=', 'documents.id')
            ->where(fn ($w) => $since
                ? $w->whereNull('ds.updated_at')->orWhere('ds.updated_at', '<', $since)
                : $w->whereNull('ds.updated_at'));
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

    /**
     * Versi bawaan yang dibuka di menu admin (Metadata/Aturan) — 1 di seluruh sistem, jatuh ke
     * versi terbaru bila belum ada yang ditandai. TIDAK menentukan akses petugas: periode
     * lapangan dibuka/ditutup lewat status siklus (SurveyCycle::isFieldOpen).
     */
    public static function active(): self
    {
        return static::where('is_active', true)->first()
            ?? static::orderByDesc('id')->firstOrFail();
    }
}
