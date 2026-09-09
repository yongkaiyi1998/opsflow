<?php

namespace App\Policies;

use App\ApprovalAssignmentStatus;
use App\Models\PurchaseRequest;
use App\Models\User;
use App\PurchaseRequestStatus;

class PurchaseRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isActive();
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, PurchaseRequest $purchaseRequest): bool
    {
        return $user->isAdmin()
            || $purchaseRequest->requester_id === $user->id
            || $purchaseRequest->approvalInstances()
                ->whereHas('steps.assignments', fn ($query) => $query
                    ->where('approver_id', $user->id)
                    ->where('status', ApprovalAssignmentStatus::Pending->value))
                ->exists();
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->isActive();
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, PurchaseRequest $purchaseRequest): bool
    {
        return $purchaseRequest->requester_id === $user->id
            && $purchaseRequest->status === PurchaseRequestStatus::Draft;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, PurchaseRequest $purchaseRequest): bool
    {
        return $this->update($user, $purchaseRequest)
            && ! $purchaseRequest->approvalInstances()->exists();
    }

    public function submit(User $user, PurchaseRequest $purchaseRequest): bool
    {
        return $this->update($user, $purchaseRequest);
    }

    public function addAttachment(User $user, PurchaseRequest $purchaseRequest): bool
    {
        return $this->update($user, $purchaseRequest);
    }

    public function deleteAttachment(User $user, PurchaseRequest $purchaseRequest): bool
    {
        return $this->update($user, $purchaseRequest);
    }
}
