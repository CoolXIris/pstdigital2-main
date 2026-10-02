<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatbotKnowledgeChunk extends Model
{
    protected $guarded = [];

    public function source(): BelongsTo
    {
        return $this->belongsTo(ChatbotKnowledgeSource::class, 'source_id');
    }
}
