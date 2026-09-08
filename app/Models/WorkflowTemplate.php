<?php

namespace App\Models;

use App\MasterDataStatus;
use App\WorkflowModuleType;
use Database\Factories\WorkflowTemplateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'code', 'module_type', 'description', 'status'])]
class WorkflowTemplate extends Model
{
    /** @use HasFactory<WorkflowTemplateFactory> */
    use HasFactory;

    public function versions(): HasMany
    {
        return $this->hasMany(WorkflowVersion::class)->orderByDesc('version');
    }

    protected function casts(): array
    {
        return ['module_type' => WorkflowModuleType::class, 'status' => MasterDataStatus::class];
    }
}
