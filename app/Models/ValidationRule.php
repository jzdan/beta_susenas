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
        'severity', 'message', 'note', 'active',
    ];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function qversion()
    {
        return $this->belongsTo(QuestionnaireVersion::class);
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
