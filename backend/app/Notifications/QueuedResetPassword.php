<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Password reset mail sent by the queue worker. The payload is encrypted because it carries the reset token.
 */
class QueuedResetPassword extends ResetPassword implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(#[\SensitiveParameter] string $token)
    {
        parent::__construct($token);
        $this->afterCommit();
    }

    public function backoff(): array
    {
        return [60, 300];
    }
}
