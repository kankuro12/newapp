<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class VerificationLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_signed_out_verification_link_returns_to_frontend_sign_in_with_next(): void
    {
        $user = User::factory()->unverified()->create();
        $link = URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $user->id, 'hash' => sha1($user->email)]);
        $path = parse_url($link, PHP_URL_PATH).'?'.parse_url($link, PHP_URL_QUERY);
        $this->get($path)->assertRedirect(rtrim(config('app.frontend_url'), '/').'/signin?'.http_build_query(['next' => $path]));
        $this->actingAs($user, 'tenant')->get($path)->assertRedirect();
        $this->assertNotNull($user->fresh()->email_verified_at);
    }
}
