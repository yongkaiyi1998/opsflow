<?php

namespace App\Policies;

use App\Models\DocumentIntake;
use App\Models\User;

class DocumentIntakePolicy
{
    public function view(User $user, DocumentIntake $documentIntake): bool
    {
        return $documentIntake->intakeBatch !== null
            && $user->can('view', $documentIntake->intakeBatch);
    }

    public function process(User $user, DocumentIntake $documentIntake): bool
    {
        return $this->view($user, $documentIntake);
    }

    public function verify(User $user, DocumentIntake $documentIntake): bool
    {
        return $this->view($user, $documentIntake);
    }
}
