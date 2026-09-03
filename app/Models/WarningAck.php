<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WarningAck extends Model
{
    protected $fillable = ['document_id', 'rule_code', 'target_var', 'row_index', 'note', 'by_user'];

    protected $casts = ['row_index' => 'integer'];

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'by_user');
    }

    /** Kunci gabungan sebuah peringatan pada dokumen. */
    public static function key(string $ruleCode, string $targetVar, int $rowIndex): string
    {
        return $ruleCode.'|'.$targetVar.'|'.$rowIndex;
    }

    /** [key => WarningAck] untuk satu dokumen. */
    public static function mapFor(int $documentId): array
    {
        return static::with('user:id,name')
            ->where('document_id', $documentId)->get()
            ->keyBy(fn ($a) => static::key($a->rule_code, $a->target_var, $a->row_index))
            ->all();
    }
}
