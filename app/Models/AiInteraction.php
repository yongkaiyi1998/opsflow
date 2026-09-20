<?php

namespace App\Models;

use App\AiInteractionStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable([
    'feature', 'provider', 'model', 'subject_type', 'subject_id', 'prompt_version',
    'schema_version', 'status', 'idempotency_key', 'input_hash', 'request_metadata',
    'response_payload', 'latency_ms', 'error_code', 'error_message',
    'processing_started_at', 'created_by',
])]
class AiInteraction extends Model
{
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    protected function casts(): array
    {
        return [
            'status' => AiInteractionStatus::class,
            'request_metadata' => 'array',
            'response_payload' => 'array',
            'processing_started_at' => 'datetime',
        ];
    }
}
