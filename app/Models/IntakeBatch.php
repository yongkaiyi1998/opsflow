<?php

namespace App\Models;

use App\DocumentIntakeStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['uploaded_by', 'submission_key'])]
class IntakeBatch extends Model
{
    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function documentIntakes(): HasMany
    {
        return $this->hasMany(DocumentIntake::class)->orderBy('id');
    }

    /** @return array<string, int> */
    public function statusCounts(): array
    {
        $counts = collect(DocumentIntakeStatus::cases())
            ->mapWithKeys(fn (DocumentIntakeStatus $status): array => [$status->value => 0])
            ->all();

        foreach ($this->documentIntakes as $document) {
            $counts[$document->status->value]++;
        }

        return $counts;
    }

    public function derivedStatus(): DocumentIntakeStatus
    {
        $counts = $this->statusCounts();
        $total = $this->documentIntakes->count();

        if ($counts[DocumentIntakeStatus::Processing->value] > 0) {
            return DocumentIntakeStatus::Processing;
        }

        if ($counts[DocumentIntakeStatus::NeedsVerification->value] > 0) {
            return DocumentIntakeStatus::NeedsVerification;
        }

        if ($counts[DocumentIntakeStatus::Failed->value] > 0) {
            return DocumentIntakeStatus::Failed;
        }

        if ($total > 0 && $total === $counts[DocumentIntakeStatus::Verified->value] + $counts[DocumentIntakeStatus::Skipped->value]) {
            return DocumentIntakeStatus::Verified;
        }

        return DocumentIntakeStatus::Pending;
    }
}
