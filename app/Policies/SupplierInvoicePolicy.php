<?php

namespace App\Policies;

use App\ApprovalAssignmentStatus;
use App\Models\SupplierInvoice;
use App\Models\User;
use App\SupplierInvoiceStatus;
use App\UserRole;

class SupplierInvoicePolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $this->canManage($user);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, SupplierInvoice $supplierInvoice): bool
    {
        return $this->canManage($user)
            || ($user->isActive() && $supplierInvoice->approvalInstances()
                ->whereHas('steps.assignments', fn ($query) => $query
                    ->where('approver_id', $user->id)
                    ->where('status', ApprovalAssignmentStatus::Pending->value))
                ->exists());
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $this->canManage($user);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, SupplierInvoice $supplierInvoice): bool
    {
        return $this->canManage($user)
            && in_array($supplierInvoice->status, [SupplierInvoiceStatus::Draft, SupplierInvoiceStatus::ChangesRequested], true);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, SupplierInvoice $supplierInvoice): bool
    {
        return $this->canManage($user)
            && $supplierInvoice->status === SupplierInvoiceStatus::Draft
            && ! $supplierInvoice->approvalInstances()->exists();
    }

    public function submit(User $user, SupplierInvoice $supplierInvoice): bool
    {
        return $this->canManage($user)
            && $supplierInvoice->status === SupplierInvoiceStatus::Draft;
    }

    public function resubmit(User $user, SupplierInvoice $supplierInvoice): bool
    {
        return $this->canManage($user)
            && $supplierInvoice->status === SupplierInvoiceStatus::ChangesRequested;
    }

    public function withdraw(User $user, SupplierInvoice $supplierInvoice): bool
    {
        return $this->canManage($user)
            && in_array($supplierInvoice->status, [SupplierInvoiceStatus::InApproval, SupplierInvoiceStatus::ChangesRequested], true);
    }

    public function addAttachment(User $user, SupplierInvoice $supplierInvoice): bool
    {
        return $this->update($user, $supplierInvoice);
    }

    public function deleteAttachment(User $user, SupplierInvoice $supplierInvoice): bool
    {
        return $this->update($user, $supplierInvoice);
    }

    private function canManage(User $user): bool
    {
        return $user->isActive()
            && in_array($user->role, [UserRole::Finance, UserRole::Admin], true);
    }
}
