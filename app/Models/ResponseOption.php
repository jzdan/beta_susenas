<?php

namespace App\Models;

use App\Models\Concerns\GuardsLockedVersion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResponseOption extends Model
{
    use GuardsLockedVersion;

    public $timestamps = false;

    protected $fillable = ['question_id', 'code', 'label', 'order'];

    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }

    public function guardQversionId(): ?int
    {
        return Block::whereKey(Question::whereKey($this->question_id)->value('block_id'))->value('qversion_id');
    }
}
