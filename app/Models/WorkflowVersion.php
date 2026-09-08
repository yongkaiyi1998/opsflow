<?php

namespace App\Models;

use App\WorkflowVersionStatus;
use Database\Factories\WorkflowVersionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

#[Fillable(['effective_from', 'effective_until'])]
class WorkflowVersion extends Model
{
    /** @use HasFactory<WorkflowVersionFactory> */
    use HasFactory;

    public function template(): BelongsTo
    {
        return $this->belongsTo(WorkflowTemplate::class, 'workflow_template_id');
    }

    public function ruleGroups(): HasMany
    {
        return $this->hasMany(WorkflowRuleGroup::class)->orderBy('priority')->orderBy('id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    public function isDraft(): bool
    {
        return $this->status === WorkflowVersionStatus::Draft;
    }

    protected static function booted(): void
    {
        static::updating(function (self $version): void {
            if (WorkflowVersionStatus::tryFrom((string) $version->getRawOriginal('status')) !== WorkflowVersionStatus::Draft) {
                throw new LogicException('Published and archived workflow versions are immutable.');
            }
        });

        static::deleting(function (self $version): void {
            if (! $version->isDraft()) {
                throw new LogicException('Only draft workflow versions may be deleted.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'status' => WorkflowVersionStatus::class,
            'effective_from' => 'datetime',
            'effective_until' => 'datetime',
            'published_at' => 'datetime',
        ];
    }
}
