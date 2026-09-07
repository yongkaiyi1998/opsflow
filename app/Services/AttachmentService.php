<?php

namespace App\Services;

use App\Models\Attachment;
use App\Models\User;
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
                $lockedAttachable = $this->lockModel($attachable);
                Gate::forUser($uploadedBy)->authorize('addAttachment', $lockedAttachable);

                $attachment = new Attachment([
                    'original_name' => basename($file->getClientOriginalName()),
                    'stored_name' => $storedName,
                    'disk' => $disk,
                    'path' => $path,
                    'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
                    'size' => $file->getSize(),
                    'uploaded_by' => $uploadedBy->id,
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

        $storedFile = DB::transaction(function () use ($attachment, $attachable, $user): array {
            $lockedAttachable = $this->lockModel($attachable);
            $lockedAttachment = Attachment::whereKey($attachment->getKey())->lockForUpdate()->firstOrFail();

            if ($lockedAttachment->attachable_type !== $attachment->attachable_type
                || (string) $lockedAttachment->attachable_id !== (string) $lockedAttachable->getKey()) {
                throw new LogicException('The attachment parent changed while it was being deleted.');
            }

            $lockedAttachment->setRelation('attachable', $lockedAttachable);
            Gate::forUser($user)->authorize('delete', $lockedAttachment);
            $lockedAttachment->delete();

            return ['disk' => $lockedAttachment->disk, 'path' => $lockedAttachment->path];
        }, 5);

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
}
