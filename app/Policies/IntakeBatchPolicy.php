<?php

namespace App\Policies;

use App\IntakeDocumentType;
use App\Models\IntakeBatch;
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
        $containsReceipt = $intakeBatch->documentIntakes()
            ->where('document_type', IntakeDocumentType::ExpenseReceipt->value)
            ->exists();

        if ($containsReceipt) {
            $containsOtherDocumentType = $intakeBatch->documentIntakes()
                ->where('document_type', '!=', IntakeDocumentType::ExpenseReceipt->value)
                ->exists();

            return ! $containsOtherDocumentType
                && $user->isActive()
                && $user->department_id !== null
                && $intakeBatch->uploaded_by === $user->id;
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
