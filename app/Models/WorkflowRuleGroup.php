<?php

namespace App\Models;

use App\WorkflowVersionStatus;
use Database\Factories\WorkflowRuleGroupFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

#[Fillable(['workflow_version_id', 'name', 'priority', 'is_default'])]
class WorkflowRuleGroup extends Model
{
    /** @use HasFactory<WorkflowRuleGroupFactory> */
    use HasFactory;

    public function version(): BelongsTo
    {
        return $this->belongsTo(WorkflowVersion::class, 'workflow_version_id');
    }

    public function rules(): HasMany
    {
        return $this->hasMany(WorkflowRule::class);
    }

    public function steps(): HasMany
    {
        return $this->hasMany(WorkflowStep::class)->orderBy('step_order');
    }

    protected function casts(): array
    {
        return ['is_default' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::saving(function (self $group): void {
            $group->ensureDraftVersion();
        });
        static::deleting(function (self $group): void {
            $group->ensureDraftVersion();
        });
    }

    private function ensureDraftVersion(): void
    {
        if (! WorkflowVersion::whereKey($this->workflow_version_id)->where('status', WorkflowVersionStatus::Draft->value)->exists()) {
            throw new LogicException('Published and archived workflow configuration is immutable.');
        }
    }
}
