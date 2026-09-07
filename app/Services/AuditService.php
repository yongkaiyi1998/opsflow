<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\User;
use App\Support\Money;
use BackedEnum;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AuditService
{
    /** @var list<string> */
    private const SENSITIVE_KEYS = [
        'password',
        'password_confirmation',
        'remember_token',
        'token',
        'api_key',
        'secret',
    ];

    /**
     * @param  array<string, mixed>  $newValues
     * @param  array<string, mixed>  $metadata
     */
    public function logCreated(Model $subject, ?User $actor, array $newValues = [], ?Request $request = null, array $metadata = []): ActivityLog
    {
        return $this->createLog($subject, $actor, $this->actionFor($subject, 'CREATED'), null, $newValues, $request, $metadata);
    }

    /**
     * @param  array<string, mixed>  $oldValues
     * @param  array<string, mixed>  $newValues
     * @param  array<string, mixed>  $metadata
     */
    public function logUpdated(Model $subject, ?User $actor, array $oldValues, array $newValues, ?Request $request = null, array $metadata = []): ?ActivityLog
    {
        [$changedOldValues, $changedNewValues] = $this->changedValues($oldValues, $newValues);

        if ($changedOldValues === [] && $changedNewValues === []) {
            return null;
        }

        return $this->createLog($subject, $actor, $this->actionFor($subject, 'UPDATED'), $changedOldValues, $changedNewValues, $request, $metadata);
    }

    /** @param array<string, mixed> $metadata */
    public function logStatusChange(Model $subject, ?User $actor, BackedEnum|string|null $oldStatus, BackedEnum|string|null $newStatus, ?Request $request = null, array $metadata = []): ActivityLog
    {
        return $this->createLog(
            $subject,
            $actor,
            $this->actionFor($subject, 'STATUS_CHANGED'),
            ['status' => $oldStatus],
            ['status' => $newStatus],
            $request,
            $metadata,
        );
    }

    /**
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     * @param  array<string, mixed>  $metadata
     */
    private function createLog(Model $subject, ?User $actor, string $action, ?array $oldValues, ?array $newValues, ?Request $request, array $metadata): ActivityLog
    {
        $activityLog = new ActivityLog([
            'action' => $action,
            'old_values' => $oldValues === null ? null : $this->normalizeArray($oldValues),
            'new_values' => $newValues === null ? null : $this->normalizeArray($newValues),
            'metadata' => $this->normalizeArray($metadata),
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
        ]);

        $activityLog->subject()->associate($subject);
        $activityLog->user()->associate($actor);
        $activityLog->save();

        return $activityLog;
    }

    /**
     * @param  array<string, mixed>  $oldValues
     * @param  array<string, mixed>  $newValues
     * @return array{array<string, mixed>, array<string, mixed>}
     */
    private function changedValues(array $oldValues, array $newValues): array
    {
        $normalizedOldValues = $this->normalizeArray($oldValues);
        $normalizedNewValues = $this->normalizeArray($newValues);
        $keys = array_unique([...array_keys($normalizedOldValues), ...array_keys($normalizedNewValues)]);
        $changedOldValues = [];
        $changedNewValues = [];

        foreach ($keys as $key) {
            $oldExists = array_key_exists($key, $normalizedOldValues);
            $newExists = array_key_exists($key, $normalizedNewValues);

            if ($oldExists === $newExists && ($normalizedOldValues[$key] ?? null) === ($normalizedNewValues[$key] ?? null)) {
                continue;
            }

            if ($oldExists) {
                $changedOldValues[$key] = $normalizedOldValues[$key];
            }

            if ($newExists) {
                $changedNewValues[$key] = $normalizedNewValues[$key];
            }
        }

        return [$changedOldValues, $changedNewValues];
    }

    private function actionFor(Model $subject, string $event): string
    {
        return Str::upper(Str::snake(class_basename($subject))).'_'.$event;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function normalizeArray(array $values): array
    {
        $normalized = [];

        foreach ($values as $key => $value) {
            if (is_string($key) && in_array(Str::lower($key), self::SENSITIVE_KEYS, true)) {
                continue;
            }

            $normalized[$key] = $this->normalizeValue($value);
        }

        return $normalized;
    }

    private function normalizeValue(mixed $value): mixed
    {
        return match (true) {
            $value instanceof BackedEnum => $value->value,
            $value instanceof Money => $value->decimal(),
            $value instanceof DateTimeInterface => $value->format(DateTimeInterface::ATOM),
            is_array($value) => $this->normalizeArray($value),
            is_null($value), is_scalar($value) => $value,
            default => (string) $value,
        };
    }
}
