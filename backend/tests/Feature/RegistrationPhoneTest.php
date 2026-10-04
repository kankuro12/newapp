<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class RegistrationPhoneTest extends TestCase
{
    use RefreshDatabase;

    private function input(): array
    {
        return ['name' => 'Phone owner', 'country_code' => 'NP', 'email' => 'phone-owner@example.test', 'password' => 'password-123', 'password_confirmation' => 'password-123'];
    }

    public function test_signup_rejects_missing_blank_malformed_and_oversized_phone_without_creating_users(): void
    {
        $this->postJson('/register', $this->input())->assertUnprocessable()->assertJsonValidationErrors('phone');
        foreach (['', '   ', 'abc9800000000', '123', '+97712345678901234567890', '+977+9800000000'] as $phone) {
            $this->postJson('/register', [...$this->input(), 'phone' => $phone])->assertUnprocessable()->assertJsonValidationErrors('phone');
        }
        $this->assertSame(0, User::count());
    }

    public function test_signup_persists_nepali_digits_and_international_formatting_as_a_phone_string(): void
    {
        Notification::fake();
        $this->postJson('/register', [...$this->input(), 'phone' => '+९७७ ९८०-१२३-४५६७'])->assertCreated();
        $this->assertDatabaseHas('users', ['email' => $this->input()['email'], 'country_code' => 'NP', 'phone' => '9801234567']);
        $this->getJson('/api/me')->assertOk()->assertJsonPath('data.phone', '9801234567')->assertJsonPath('data.country_code', 'NP');
        $this->getJson('/api/platform/me')->assertUnauthorized();
        $this->assertNull(User::first()->email_verified_at);
    }

    public function test_signup_keeps_national_landline_prefix_and_does_not_invent_country_code(): void
    {
        Notification::fake();
        $this->postJson('/register', [...$this->input(), 'phone' => '01-4567890'])->assertCreated();
        $this->assertDatabaseHas('users', ['phone' => '014567890']);
    }

    public function test_country_is_required_and_pasted_international_phone_must_match_selected_country(): void
    {
        $input = [...$this->input(), 'phone' => '9801234567'];
        unset($input['country_code']);
        $this->postJson('/register', $input)->assertUnprocessable()->assertJsonValidationErrors('country_code');
        $this->postJson('/register', [...$input, 'country_code' => 'ZZ'])->assertUnprocessable()->assertJsonValidationErrors('country_code');
        $this->postJson('/register', [...$input, 'country_code' => 'IN', 'phone' => '+9779801234567'])->assertUnprocessable()->assertJsonValidationErrors('phone');
        $this->assertSame(0, User::count());
    }

    public function test_selected_foreign_country_is_saved_separately_from_national_phone(): void
    {
        Notification::fake();
        $this->postJson('/register', [...$this->input(), 'country_code' => 'IN', 'phone' => '9876543210'])->assertCreated();
        $this->assertDatabaseHas('users', ['country_code' => 'IN', 'phone' => '9876543210']);
    }
}
