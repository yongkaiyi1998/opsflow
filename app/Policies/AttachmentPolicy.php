<?php

namespace App\Policies;

use App\Models\Attachment;
use App\Models\SupplierInvoice;
use App\Models\User;

class AttachmentPolicy
{
    public function view(User $user, Attachment $attachment): bool
    {
        return $attachment->attachable !== null
            && $user->can('view', $attachment->attachable);
    }

    public function delete(User $user, Attachment $attachment): bool
    {
        return $attachment->attachable !== null
            && ! $this->isIntakeSource($attachment)
            && $user->can('deleteAttachment', $attachment->attachable);
    }

    private function isIntakeSource(Attachment $attachment): bool
    {
        if ($attachment->attachable_type !== SupplierInvoice::class) {
            return false;
        }

        return $attachment->relationLoaded('sourceDocumentIntake')
            ? $attachment->sourceDocumentIntake !== null
            : $attachment->sourceDocumentIntake()->exists();
    }
}
