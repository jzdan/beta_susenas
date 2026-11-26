<?php

namespace App\Models;

use App\Models\Concerns\GuardsLockedVersion;
use App\Models\Concerns\MarksRulesChanged;
use Illuminate\Database\Eloquent\Model;

class ValidationRule extends Model
{
    use GuardsLockedVersion, MarksRulesChanged;

    public $timestamps = false;

    protected $fillable = [
        'qversion_id', 'code', 'scope', 'row_block', 'target_var', 'expr',
        'severity', 'message', 'note', 'active', 'test_cases',
    ];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'test_cases' => 'array'];
    }

    public function qversion()
    {
        return $this->belongsTo(QuestionnaireVersion::class);
    }

    public function revisions()
    {
        return $this->hasMany(RuleRevision::class, 'rule_id');
    }

    /**
     * Riwayat (Inc 158): setiap perubahan lewat model direkam ke rule_revisions. Aturan bawaan
     * (seed/impor) belum punya revisi → sebelum perubahan pertama keadaan lamanya disimpan
     * sebagai revisi `awal`, supaya selalu bisa dikembalikan. Update massal query builder
     * (ganti kode blok, nyalakan/matikan massal) tidak terekam di sini.
     */
    protected static function booted(): void
    {
        static::updating(function (self $r) {
            if (! $r->isDirty(RuleRevision::FIELDS) || ! $r->exists) {
                return;
            }
            if (! RuleRevision::where('rule_id', $r->id)->exists()) {
                RuleRevision::create(['rule_id' => $r->id, 'action' => 'awal',
                    'data' => RuleRevision::snapshot($r, true), 'by_user' => null, 'at' => now()]);
            }
        });
        static::updated(function (self $r) {
            if (! $r->wasChanged(RuleRevision::FIELDS)) {
                return;
            }
            $action = RuleRevision::$nextAction;
            RuleRevision::$nextAction = null;
            if ($action === null) {
                $only = array_keys(array_intersect_key($r->getChanges(), array_flip(RuleRevision::FIELDS)));
                $action = $only === ['active'] ? ($r->active ? 'nyalakan' : 'matikan') : 'ubah';
            }
            RuleRevision::record($r, $action);
        });
    }

    /**
     * Bila ekspresi memuat tepat satu angka ambang perbandingan (mis. `> 0.25`),
     * kembalikan angka itu sebagai string — supaya bisa diubah tanpa menyentuh
     * ekspresi. `0` diabaikan (penjaga tanda, bukan ambang). Null bila ambigu.
     */
    public function soleThreshold(): ?string
    {
        preg_match_all('/(?:>=|<=|>|<)\s*(-?\d+(?:\.\d+)?)/', $this->expr, $m);
        $nums = array_values(array_unique(array_filter($m[1], fn ($n) => (float) $n != 0.0)));
        if (count($nums) !== 1) {
            return null;
        }
        preg_match_all('/(-?\d+(?:\.\d+)?)\s*(?:>=|<=|>|<)/', $this->expr, $m2);
        if (array_filter($m2[1], fn ($n) => (float) $n != 0.0)) {
            return null;
        }

        return $nums[0];
    }

    /** Ekspresi dengan angka ambang tunggal diganti nilai baru. */
    public function withThreshold(string $new): string
    {
        $old = $this->soleThreshold();
        if ($old === null) {
            return $this->expr;
        }

        return preg_replace(
            '/((?:>=|<=|>|<)\s*)'.preg_quote($old, '/').'(?![\d.])/',
            '${1}'.$new,
            $this->expr,
        );
    }

    public function guardQversionId(): ?int
    {
        return $this->qversion_id;
    }
}
