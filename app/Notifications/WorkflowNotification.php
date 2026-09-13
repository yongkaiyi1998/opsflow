<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

abstract class WorkflowNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $reference,
        public readonly string $message,
        public readonly string $targetUrl,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array{event: string, reference: string, message: string, target_url: string} */
    public function toArray(object $notifiable): array
    {
        return [
            'event' => class_basename($this),
            'reference' => $this->reference,
            'message' => $this->message,
            'target_url' => $this->targetUrl,
        ];
    }
}
