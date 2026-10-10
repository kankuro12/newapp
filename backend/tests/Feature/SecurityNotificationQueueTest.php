<?php
namespace Tests\Feature;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
class SecurityNotificationQueueTest extends TestCase
{
    public function test_reset_and_verification_use_encrypted_after_commit_jobs(): void
    {
        Queue::fake();
        $user = User::factory()->make(['email_verified_at' => null]);
        $user->sendPasswordResetNotification('private-reset-token');
        $user->sendEmailVerificationNotification();
        Queue::assertPushed(SendQueuedNotifications::class, 2);
        Queue::assertPushed(SendQueuedNotifications::class, fn ($job) => $job->notification instanceof ShouldBeEncrypted && $job->shouldBeEncrypted && $job->afterCommit === true);
    }
}