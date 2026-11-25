<?php

namespace App\Models;

use App\Models\Concerns\GuardsLockedVersion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BlockRow extends Model
{
    use GuardsLockedVersion;

    public $timestamps = false;

    protected $fillable = ['block_id', 'row_index', 'code', 'label', 'order', 'ref'];

    protected function casts(): array
    {
        return ['ref' => 'array'];
    }

    public function block(): BelongsTo
    {
        return $this->belongsTo(Block::class);
    }

    public function guardQversionId(): ?int
    {
        return Block::whereKey($this->block_id)->value('qversion_id');
    }
}
