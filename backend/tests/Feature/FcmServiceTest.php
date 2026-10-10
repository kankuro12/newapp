<?php

namespace Tests\Feature;

use App\Service\FcmService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FcmServiceTest extends TestCase
{
    private function configure(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $pem);
        config(['services.fcm.project_id' => 'test-project', 'services.fcm.client_email' => 'sender@test-project.iam.gserviceaccount.com', 'services.fcm.private_key' => $pem]);
    }

    public function test_signed_oauth_and_exact_device_send(): void
    {
        $this->configure();
        Http::preventStrayRequests();
        Http::fake(['oauth2.googleapis.com/*' => Http::response(['access_token' => 'access-secret', 'expires_in' => 3600]), 'fcm.googleapis.com/*' => Http::response(['name' => 'projects/test-project/messages/123'])]);
        $result = app(FcmService::class)->send('device-secret', ['title' => 'Package reminder', 'body' => 'Review packages']);
        $this->assertSame(['status' => 'sent', 'provider_id' => 'projects/test-project/messages/123'], $result);
        Http::assertSent(fn ($r) => $r->url() === 'https://oauth2.googleapis.com/token' && substr_count($r['assertion'], '.') === 2);
        Http::assertSent(fn ($r) => $r->url() === 'https://fcm.googleapis.com/v1/projects/test-project/messages:send' && $r->hasHeader('Authorization', 'Bearer access-secret') && $r['message']['token'] === 'device-secret');
    }

    public function test_unregistered_token_is_classified_without_leaking_response(): void
    {
        $this->configure();
        Http::fake(['oauth2.googleapis.com/*' => Http::response(['access_token' => 'access-secret', 'expires_in' => 3600]), 'fcm.googleapis.com/*' => Http::response(['error' => ['details' => [['@type' => 'type.googleapis.com/google.firebase.fcm.v1.FcmError', 'errorCode' => 'UNREGISTERED']], 'message' => 'device-secret']], 404)]);
        $this->assertSame(['status' => 'invalid', 'error_code' => 'token_unregistered'], app(FcmService::class)->send('device-secret', ['title' => 'Reminder', 'body' => 'Review packages']));
    }

    public function test_missing_configuration_makes_no_network_request(): void
    {
        config(['services.fcm' => []]);
        Http::fake();
        $this->assertSame(['status' => 'unavailable', 'error_code' => 'fcm_not_configured'], app(FcmService::class)->send('token', ['title' => 'Reminder', 'body' => 'Review']));
        Http::assertNothingSent();
    }

    public function test_installation_id_uses_fid_target_and_safe_same_origin_link(): void
    {
        $this->configure();
        config(['app.frontend_url' => 'https://book.example.test']);
        Http::fake(['oauth2.googleapis.com/*' => Http::response(['access_token' => 'access-secret', 'expires_in' => 3600]), 'fcm.googleapis.com/*' => Http::response(['name' => 'projects/test-project/messages/123'])]);
        app(FcmService::class)->send('installation-id', ['title' => 'Business Book', 'body' => 'Review updates', 'url' => 'https://book.example.test/notifications'], 'fid');
        Http::assertSent(fn ($r) => str_contains($r->url(), 'messages:send') && $r['message']['fid'] === 'installation-id' && ! isset($r['message']['token']) && $r['message']['webpush']['fcm_options']['link'] === 'https://book.example.test/notifications');
    }

    public function test_short_lived_oauth_is_reused_for_multiple_devices(): void
    {
        $this->configure();
        Http::fake(['oauth2.googleapis.com/*' => Http::response(['access_token' => 'access-secret', 'expires_in' => 3600]), 'fcm.googleapis.com/*' => Http::response(['name' => 'projects/test-project/messages/123'])]);
        $service = app(FcmService::class);
        $service->send('device-one', ['title' => 'Reminder', 'body' => 'Review']);
        $service->send('device-two', ['title' => 'Reminder', 'body' => 'Review']);
        $this->assertCount(1, Http::recorded(fn ($request) => $request->url() === 'https://oauth2.googleapis.com/token'));
    }
}
