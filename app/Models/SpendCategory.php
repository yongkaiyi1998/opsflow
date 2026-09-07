<?php

namespace App\Models;

use App\MasterDataStatus;
use Database\Factories\SpendCategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'code', 'status'])]
class SpendCategory extends Model
{
    /** @use HasFactory<SpendCategoryFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['status' => MasterDataStatus::class];
    }
}
