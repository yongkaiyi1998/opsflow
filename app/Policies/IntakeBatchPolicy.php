<?php

namespace App\Policies;

use App\IntakeDocumentType;
use App\Models\ExpenseClaim;
use App\Models\IntakeBatch;
use App\Models\PurchaseRequest;
use App\Models\User;
use App\UserRole;

class IntakeBatchPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $this->canAccess($user);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, IntakeBatch $intakeBatch): bool
    {
        $documentTypes = $intakeBatch->documentIntakes()->reorder()->distinct()->pluck('document_type');

        if ($documentTypes->count() !== 1) {
            return false;
        }

        $storedDocumentType = $documentTypes->first();
        $documentType = $storedDocumentType instanceof IntakeDocumentType
            ? $storedDocumentType
            : IntakeDocumentType::tryFrom((string) $storedDocumentType);

        if ($documentType === IntakeDocumentType::ExpenseReceipt) {
            return $intakeBatch->uploaded_by === $user->id
                && $user->can('create', ExpenseClaim::class);
        }

        if ($documentType === IntakeDocumentType::PurchaseQuotation) {
            return $intakeBatch->uploaded_by === $user->id
                && $user->can('create', PurchaseRequest::class);
        }

        return $this->canAccess($user);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $this->canAccess($user);
    }

    /**
     * Determine whether the user can update the model.
     */
    private function canAccess(User $user): bool
    {
        return $user->isActive()
            && in_array($user->role, [UserRole::Finance, UserRole::Admin], true);
    }
}
