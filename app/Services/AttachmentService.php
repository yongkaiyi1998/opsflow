<?php

namespace App\Services;

use App\Models\Attachment;
use App\Models\ExpenseClaim;
use App\Models\ExpenseItem;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\File;
use LogicException;
use RuntimeException;
use Throwable;

class AttachmentService
{
    public function store(Model $attachable, UploadedFile $file, User $uploadedBy): Attachment
    {
        Gate::forUser($uploadedBy)->authorize('addAttachment', $attachable);
        Validator::validate(['attachment' => $file], [
            'attachment' => ['required', File::types(config('attachments.types'))->max(config('attachments.max_size'))],
        ]);

        $disk = (string) config('attachments.disk');
        $this->ensurePrivateDisk($disk);
        $extension = $file->extension();
        $storedName = Str::uuid()->toString().($extension ? ".{$extension}" : '');
        $directory = 'attachments/'.now()->format('Y/m');
        $path = $file->storeAs($directory, $storedName, $disk);

        if ($path === false) {
            throw new RuntimeException('The attachment could not be stored.');
        }

        try {
            return DB::transaction(function () use ($attachable, $file, $uploadedBy, $storedName, $disk, $path): Attachment {
                $lockedParent = $this->lockAuthorizationParent($attachable);
                $lockedUser = $this->lockActiveUser($uploadedBy);
                $lockedAttachable = $this->lockAttachable($attachable, $lockedParent);
                Gate::forUser($lockedUser)->authorize('addAttachment', $lockedAttachable);

                $attachment = new Attachment([
                    'original_name' => basename($file->getClientOriginalName()),
                    'stored_name' => $storedName,
                    'disk' => $disk,
                    'path' => $path,
                    'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
                    'size' => $file->getSize(),
                    'uploaded_by' => $lockedUser->id,
                ]);
                $attachment->attachable()->associate($lockedAttachable);
                $attachment->save();

                return $attachment;
            }, 5);
        } catch (Throwable $exception) {
            Storage::disk($disk)->delete($path);

            throw $exception;
        }
    }

    public function delete(Attachment $attachment, User $user): void
    {
        Gate::forUser($user)->authorize('delete', $attachment);

        $attachable = $attachment->attachable;

        if (! $attachable instanceof Model) {
            throw new AuthorizationException;
        }

        $storedFile = DB::transaction(function () use ($attachment, $attachable, $user): array {
            $lockedParent = $this->lockAuthorizationParent($attachable);
            $lockedUser = $this->lockActiveUser($user);
            $lockedAttachable = $this->lockAttachable($attachable, $lockedParent);
            $lockedAttachment = Attachment::whereKey($attachment->getKey())->lockForUpdate()->firstOrFail();

            if ($lockedAttachment->attachable_type !== $attachment->attachable_type
                || (string) $lockedAttachment->attachable_id !== (string) $lockedAttachable->getKey()) {
                throw new LogicException('The attachment parent changed while it was being deleted.');
            }

            $lockedAttachment->setRelation('attachable', $lockedAttachable);
            Gate::forUser($lockedUser)->authorize('delete', $lockedAttachment);
            $preserveIntakeSource = $lockedAttachment->sourceExpenseReceiptIntake()->exists();
            $lockedAttachment->delete();

            return [
                'disk' => $lockedAttachment->disk,
                'path' => $lockedAttachment->path,
                'preserve_intake_source' => $preserveIntakeSource,
            ];
        }, 5);

        if ($storedFile['preserve_intake_source']) {
            return;
        }

        $disk = Storage::disk($storedFile['disk']);

        if ($disk->exists($storedFile['path']) && ! $disk->delete($storedFile['path'])) {
            throw new RuntimeException('The attachment file could not be deleted.');
        }
    }

    private function ensurePrivateDisk(string $disk): void
    {
        if ($disk === '' || $disk === 'public' || config("filesystems.disks.{$disk}.visibility") === 'public') {
            throw new LogicException('Attachments must use a private filesystem disk.');
        }
    }

    private function lockModel(Model $model): Model
    {
        if (! $model->exists || $model->getKey() === null) {
            throw new LogicException('Attachments require a persisted parent record.');
        }

        return $model->newQuery()
            ->whereKey($model->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function lockAuthorizationParent(Model $attachable): Model
    {
        if ($attachable instanceof ExpenseItem) {
            if (! $attachable->exists || $attachable->expense_claim_id === null) {
                throw new LogicException('Attachments require a persisted parent record.');
            }

            return ExpenseClaim::query()
                ->whereKey($attachable->expense_claim_id)
                ->lockForUpdate()
                ->firstOrFail();
        }

        return $this->lockModel($attachable);
    }

    private function lockAttachable(Model $attachable, Model $lockedParent): Model
    {
        if (! $attachable instanceof ExpenseItem) {
            return $lockedParent;
        }

        $lockedItem = ExpenseItem::query()
            ->whereKey($attachable->getKey())
            ->where('expense_claim_id', $lockedParent->getKey())
            ->lockForUpdate()
            ->firstOrFail();
        $lockedItem->setRelation('expenseClaim', $lockedParent);

        return $lockedItem;
    }

    private function lockActiveUser(User $user): User
    {
        $lockedUser = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

        if (! $lockedUser->isActive()) {
            throw new AuthorizationException;
        }

        return $lockedUser;
    }
}
