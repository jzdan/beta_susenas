<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ColumnAlias extends Model
{
    protected $fillable = ['qversion_id', 'source_col', 'target_col'];

    public function qversion(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(QuestionnaireVersion::class, 'qversion_id');
    }

    /** [source_col => target_col] untuk satu versi kuesioner. */
    public static function mapFor(int $qversionId): array
    {
        return static::where('qversion_id', $qversionId)->pluck('target_col', 'source_col')->all();
    }
}
