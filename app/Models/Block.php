<?php

namespace App\Models;

use App\Models\Concerns\GuardsLockedVersion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Block extends Model
{
    use GuardsLockedVersion;

    protected $fillable = ['qversion_id', 'code', 'title', 'order', 'repeat', 'harga_kalori'];

    protected function casts(): array
    {
        return ['harga_kalori' => 'boolean'];
    }

    /** tipe berulang yang didukung */
    public const REPEATS = ['ruta', 'art', 'list', 'list_art'];

    /** faktor pengepakan art_index untuk blok list_art: idx = baris*1000 + no_art */
    public const LIST_ART_FACTOR = 1000;

    public static function packRowArt(int $row, int $art): int
    {
        return $row * self::LIST_ART_FACTOR + $art;
    }

    /** @return array{0:int,1:int} [baris (row_index komoditas), no_art] */
    public static function unpackRowArt(int $idx): array
    {
        return [intdiv($idx, self::LIST_ART_FACTOR), $idx % self::LIST_ART_FACTOR];
    }

    public function isRowBlock(): bool
    {
        return in_array($this->repeat, ['list', 'list_art'], true);
    }

    public function qversion(): BelongsTo
    {
        return $this->belongsTo(QuestionnaireVersion::class, 'qversion_id');
    }

    public function questions(): HasMany
    {
        return $this->hasMany(Question::class)->orderBy('order');
    }

    public function rows(): HasMany
    {
        return $this->hasMany(BlockRow::class)->orderBy('order');
    }

    public function guardQversionId(): ?int
    {
        return $this->qversion_id;
    }
}
