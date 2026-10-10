<?php

namespace Tests\Feature;

use App\Models\User;
use App\Service\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class NotificationDeviceTest extends TestCase
{
    use RefreshDatabase;

    private function device(string $token): array
    {
        return ['device_uuid' => (string) Str::uuid(), 'token' => $token, 'name' => 'Browser', 'consent' => true];
    }

    public function test_registration_encrypts_token_and_requires_explicit_consent(): void
    {
        $this->actingAs(User::factory()->create(), 'tenant');
        $input = $this->device('private-fcm-token');
        $this->postJson('/api/notifications/devices', [...$input, 'consent' => false])->assertUnprocessable();
        $id = $this->postJson('/api/notifications/devices', $input)->assertCreated()->json('data.id');
        $row = DB::table('notification_devices')->where('id', $id)->first();
        $this->assertSame('private-fcm-token', Crypt::decryptString($row->token_encrypted));
        $this->assertNotSame('private-fcm-token', $row->token_encrypted);
        $this->getJson('/api/notifications/devices')->assertOk()->assertJsonMissing(['token' => 'private-fcm-token']);
        $this->assertTrue((bool) DB::table('notification_preferences')->value('operational_fcm'));
    }

    public function test_foreign_active_token_cannot_be_claimed_or_revoked(): void
    {
        $this->actingAs(User::factory()->create(), 'tenant');
        $input = $this->device('owned-token');
        $id = $this->postJson('/api/notifications/devices', $input)->assertCreated()->json('data.id');
        $this->actingAs(User::factory()->create(), 'tenant');
        $this->postJson('/api/notifications/devices', $this->device('owned-token'))->assertConflict();
        $this->deleteJson('/api/notifications/devices/'.$id)->assertNotFound();
        $this->getJson('/api/notifications/devices')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_token_rotation_updates_same_device_and_revocation_disables_it(): void
    {
        $this->actingAs(User::factory()->create(), 'tenant');
        $input = $this->device('original-token');
        $id = $this->postJson('/api/notifications/devices', $input)->assertCreated()->json('data.id');
        $this->postJson('/api/notifications/devices', [...$input, 'token' => 'replacement-token'])->assertCreated()->assertJsonPath('data.id', $id);
        $this->assertDatabaseCount('notification_devices', 1);
        $this->deleteJson('/api/notifications/devices/'.$id)->assertNoContent();
        $this->assertDatabaseHas('notification_devices', ['id' => $id, 'active' => false]);
    }

    public function test_preferences_are_private_and_promotions_default_off(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();
        $this->actingAs($first, 'tenant');
        $this->getJson('/api/notifications/preferences')->assertOk()->assertJsonPath('data.promotional_email', false)->assertJsonPath('data.promotional_fcm', false);
        $this->patchJson('/api/notifications/preferences', ['promotional_email' => true])->assertOk()->assertJsonPath('data.promotional_email', true);
        $this->actingAs($second, 'tenant');
        $this->getJson('/api/notifications/preferences')->assertOk()->assertJsonPath('data.promotional_email', false);
    }

    public function test_explicit_revocation_releases_token_without_transferring_ownership(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();
        $this->actingAs($first, 'tenant');
        $oldId = $this->postJson('/api/notifications/devices', $this->device('shared-browser-token'))->assertCreated()->json('data.id');
        $this->deleteJson('/api/notifications/devices/'.$oldId)->assertNoContent();
        $this->assertDatabaseHas('notification_devices', ['id' => $oldId, 'user_id' => $first->id, 'active' => false, 'token_hash' => null, 'token_encrypted' => null]);
        $this->actingAs($second, 'tenant');
        $newId = $this->postJson('/api/notifications/devices', $this->device('shared-browser-token'))->assertCreated()->json('data.id');
        $this->assertNotSame($oldId, $newId);
        $this->actingAs($first, 'tenant');
        $this->deleteJson('/api/notifications/devices/'.$newId)->assertNotFound();
        $this->assertDatabaseHas('notification_devices', ['id' => $newId, 'user_id' => $second->id, 'active' => true]);
    }

    public function test_firebase_installation_identity_is_stored_with_target_kind(): void
    {
        $this->actingAs(User::factory()->create(), 'tenant');
        $input = [...$this->device('installation-identity'), 'target_kind' => 'fid'];
        $id = $this->postJson('/api/notifications/devices', $input)->assertCreated()->json('data.id');
        $this->assertDatabaseHas('notification_devices', ['id' => $id, 'target_kind' => 'fid']);
        $this->postJson('/api/notifications/devices', [...$input, 'target_kind' => 'topic'])->assertUnprocessable();
    }

    public function test_logout_revokes_owned_device_even_without_registration_response_id(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();
        $input = $this->device('logout-device');
        $this->actingAs($first, 'tenant');
        $id = $this->postJson('/api/notifications/devices', $input)->assertCreated()->json('data.id');
        $other = app(NotificationService::class)->registerDevice($second->id, $this->device('other-device'));
        $this->postJson('/logout', ['push_device_uuid' => $input['device_uuid']])->assertNoContent();
        $this->assertDatabaseHas('notification_devices', ['id' => $id, 'active' => false, 'token_encrypted' => null]);
        $this->assertDatabaseHas('notification_devices', ['id' => $other['id'], 'active' => true]);
    }
}
