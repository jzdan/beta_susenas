<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReferencePrice extends Model
{
    protected $fillable = ['qversion_id', 'code', 'wilayah_prefix', 'harga_min', 'harga_max'];

    protected $casts = ['harga_min' => 'float', 'harga_max' => 'float'];

    public function qversion(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(QuestionnaireVersion::class, 'qversion_id');
    }

    /**
     * Rentang harga untuk satu komoditas & wilayah, prefiks terpanjang menang.
     *
     * @param  array<string,array<string,array{min:?float,max:?float}>>  $index  [code => [prefix => range]]
     * @return array{min:?float,max:?float}|null
     */
    public static function pick(array $index, string $code, string $wilayahCode): ?array
    {
        $byPrefix = $index[$code] ?? null;
        if (! $byPrefix) {
            return null;
        }
        $best = null;
        $bestLen = -1;
        foreach ($byPrefix as $prefix => $range) {
            if (($prefix === '' || str_starts_with($wilayahCode, $prefix)) && strlen($prefix) > $bestLen) {
                $best = $range;
                $bestLen = strlen($prefix);
            }
        }
        return $best;
    }

    /** [code => [wilayah_prefix => ['min'=>..,'max'=>..]]] untuk satu versi kuesioner. */
    public static function indexFor(int $qversionId): array
    {
        $out = [];
        foreach (static::where('qversion_id', $qversionId)->get() as $rp) {
            $out[$rp->code][$rp->wilayah_prefix] = ['min' => $rp->harga_min, 'max' => $rp->harga_max];
        }
        return $out;
    }
}
