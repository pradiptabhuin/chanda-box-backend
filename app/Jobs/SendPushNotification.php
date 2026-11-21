<?php

namespace App\Jobs;

use App\Core\Push\PushInterface;
use App\Models\DeviceToken;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Pushes one notification to every registered device of a user and forgets the
 * tokens FCM reports as dead. Dispatched by PushChannel after the HTTP response.
 */
class SendPushNotification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<string, scalar|null>  $data
     */
    public function __construct(
        public int $userId,
        public string $title,
        public string $body,
        public array $data = [],
    ) {}

    public function handle(PushInterface $push): void
    {
        $tokens = DeviceToken::where('user_id', $this->userId)->pluck('token')->all();

        if ($tokens === []) {
            return;
        }

        $invalid = $push->send($tokens, $this->title, $this->body, $this->data);

        if ($invalid !== []) {
            DeviceToken::whereIn('token', $invalid)->delete();
        }
    }
}
