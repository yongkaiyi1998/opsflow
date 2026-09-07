<?php

namespace App\Policies;

use App\Models\Attachment;
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
            && $user->can('deleteAttachment', $attachment->attachable);
    }
}
