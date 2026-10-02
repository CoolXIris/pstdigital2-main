<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChatbotKnowledgeSource extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['trained_at' => 'datetime'];
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(ChatbotKnowledgeChunk::class, 'source_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by');
    }
}
