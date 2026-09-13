<?php

namespace App\Services;

use App\Models\ApprovalAssignment;
use App\Models\ExpenseClaim;
use App\Models\PurchaseRequest;
use App\Models\SupplierInvoice;
use App\Models\User;
use App\Notifications\ApprovalAssignedNotification;
use App\Notifications\ChangesRequestedNotification;
use App\Notifications\RequestApprovedNotification;
use App\Notifications\RequestRejectedNotification;
use App\Notifications\RequestResubmittedNotification;
use App\UserStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Throwable;

class WorkflowNotifier
{
    /** @param iterable<ApprovalAssignment> $assignments */
    public function assignmentsCreated(iterable $assignments, Model $business, bool $resubmitted = false): void
    {
        if (! $this->supports($business)) {
            return;
        }

        [$reference, $label] = $this->describe($business);

        foreach ($assignments as $assignment) {
            $notification = $resubmitted
                ? new RequestResubmittedNotification(
                    $reference,
                    "{$label} {$reference} was resubmitted and requires your review.",
                    route('approvals.show', $assignment->id, false),
                )
                : new ApprovalAssignedNotification(
                    $reference,
                    "{$label} {$reference} requires your approval.",
                    route('approvals.show', $assignment->id, false),
                );

            $this->afterCommit($assignment->approver_id, $notification);
        }
    }

    public function changesRequested(Model $business, User $actor, string $comment): void
    {
        [$reference, $label] = $this->describe($business);
        $this->afterCommit(
            $this->ownerId($business),
            new ChangesRequestedNotification(
                $reference,
                "{$actor->name} requested changes to {$label} {$reference}: {$comment}",
                $this->businessUrl($business),
            ),
        );
    }

    public function approved(Model $business): void
    {
        [$reference, $label] = $this->describe($business);
        $this->afterCommit(
            $this->ownerId($business),
            new RequestApprovedNotification(
                $reference,
                "{$label} {$reference} has been approved.",
                $this->businessUrl($business),
            ),
        );
    }

    public function rejected(Model $business, string $comment): void
    {
        [$reference, $label] = $this->describe($business);
        $this->afterCommit(
            $this->ownerId($business),
            new RequestRejectedNotification(
                $reference,
                "{$label} {$reference} was rejected: {$comment}",
                $this->businessUrl($business),
            ),
        );
    }

    private function afterCommit(int $recipientId, Notification $notification): void
    {
        DB::afterCommit(function () use ($recipientId, $notification): void {
            try {
                $recipient = User::query()
                    ->whereKey($recipientId)
                    ->where('status', UserStatus::Active->value)
                    ->first();

                $recipient?->notify($notification);
            } catch (Throwable $exception) {
                report($exception);
            }
        });
    }

    /** @return array{string, string} */
    private function describe(Model $business): array
    {
        return match (true) {
            $business instanceof PurchaseRequest => [$business->request_no, 'Purchase Request'],
            $business instanceof SupplierInvoice => [$business->internal_no, 'Supplier Invoice'],
            $business instanceof ExpenseClaim => [$business->claim_no, 'Expense Claim'],
            default => throw new \LogicException('Unsupported notification subject.'),
        };
    }

    private function ownerId(Model $business): int
    {
        return match (true) {
            $business instanceof PurchaseRequest => (int) $business->requester_id,
            $business instanceof SupplierInvoice => (int) $business->submitted_by,
            $business instanceof ExpenseClaim => (int) $business->employee_id,
            default => throw new \LogicException('Unsupported notification subject.'),
        };
    }

    private function businessUrl(Model $business): string
    {
        return match (true) {
            $business instanceof PurchaseRequest => route('purchase-requests.show', $business, false),
            $business instanceof SupplierInvoice => route('supplier-invoices.show', $business, false),
            $business instanceof ExpenseClaim => route('expense-claims.show', $business, false),
            default => throw new \LogicException('Unsupported notification subject.'),
        };
    }

    private function supports(Model $business): bool
    {
        return $business instanceof PurchaseRequest
            || $business instanceof SupplierInvoice
            || $business instanceof ExpenseClaim;
    }
}
