<?php

namespace Tests\Feature;

use App\Models\User;
use App\Service\FcmService;
use App\Service\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class FcmDeliveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_owned_device_receives_once_and_invalid_token_is_revoked(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $service = app(NotificationService::class);
        foreach (['valid-token', 'invalid-token'] as $token) {
            $service->registerDevice($user->id, ['device_uuid' => (string) Str::uuid(), 'token' => $token]);
        }
        $this->mock(FcmService::class, function ($mock) {
            $mock->shouldReceive('ready')->andReturn(true);
            $mock->shouldReceive('send')->once()->with('valid-token', \Mockery::type('array'), 'token')->andReturn(['status' => 'sent', 'provider_id' => 'projects/test/messages/1']);
            $mock->shouldReceive('send')->once()->with('invalid-token', \Mockery::type('array'), 'token')->andReturn(['status' => 'invalid', 'error_code' => 'token_unregistered']);
        });
        $id = $service->enqueue('welcome', $user->id, [], ['fcm'], 'first')[0];
        $service->deliver($id);
        $service->deliver($id);
        $this->assertDatabaseHas('message_deliveries', ['id' => $id, 'status' => 'sent']);
        $this->assertSame(1, DB::table('notification_devices')->where('active', true)->count());
        $this->assertDatabaseCount('message_device_deliveries', 2);
    }

    public function test_later_push_optout_prevents_network_send(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $service = app(NotificationService::class);
        $service->registerDevice($user->id, ['device_uuid' => (string) Str::uuid(), 'token' => 'token']);
        $id = $service->enqueue('promotion', $user->id, ['body' => 'Offer'], ['fcm'], 'offer')[0];
        $this->mock(FcmService::class, fn ($mock) => $mock->shouldNotReceive('send'));
        $service->deliver($id);
        $this->assertDatabaseHas('message_deliveries', ['id' => $id, 'status' => 'skipped', 'error_code' => 'consent_missing']);
    }
}
