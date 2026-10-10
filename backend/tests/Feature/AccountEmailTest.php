<?php

namespace Tests\Feature;

use App\Mail\AccountMessage;
use App\Models\User;
use App\Service\NotificationService;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class AccountEmailTest extends TestCase
{
    public function test_generic_templates_escape_names_and_campaign_content(): void
    {
        $mail = new AccountMessage('welcome', '<script>alert(1)</script>', []);
        $html = $mail->render();
        $this->assertStringContainsString('Welcome to Business Book', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $promotion = (new AccountMessage('promotion', 'Owner', ['body' => '<img src=x onerror=alert(1)>']))->render();
        $this->assertStringNotContainsString('<img src=x', $promotion);
    }

    public function test_password_reset_retains_frontend_token_and_email_without_secret_in_body(): void
    {
        config(['app.frontend_url' => 'https://frontend.example']);
        $user = User::factory()->make(['email' => 'owner+reset@example.test']);
        $mail = (new ResetPassword('secret-reset-token'))->toMail($user);
        $url = $mail->viewData['action_url'];
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame('secret-reset-token', $query['token']);
        $this->assertSame($user->email, $query['email']);
        $this->assertStringStartsWith('https://frontend.example/reset?', $url);
        $this->assertStringNotContainsString('secret-reset-token', implode(' ', $mail->viewData['lines']));
    }

    public function test_verification_preserves_signed_backend_route(): void
    {
        $user = User::factory()->make(['id' => 73]);
        $mail = (new VerifyEmail)->toMail($user);
        $url = $mail->viewData['action_url'];
        $this->assertTrue(URL::hasValidSignature(Request::create($url)));
        $this->assertStringContainsString('/email/verify/73/'.sha1($user->email), $url);
    }

    public function test_receipt_formats_integer_minor_units_without_precision_loss(): void
    {
        $data = app(NotificationService::class)->message('payment_receipt', 'Owner', ['currency' => 'NPR', 'amount_minor' => '999999999999', 'reference' => 'reference-123']);
        $this->assertStringContainsString('NPR 9999999999.99', implode(' ', $data['lines']));
    }

    public function test_templates_reject_unsafe_action_link_protocol(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        app(NotificationService::class)->message('welcome', 'Owner', ['url' => 'javascript:alert(1)']);
    }

    public function test_plaintext_preserves_action_query_separators(): void
    {
        config(['app.frontend_url' => 'https://frontend.example']);
        $data = app(NotificationService::class)->message('password_reset', 'Owner', ['url' => 'https://frontend.example/reset?token=test-token&email=owner%40example.test']);
        $text = view('emails.account-message-text', $data)->render();
        $this->assertStringContainsString('token=test-token&email=', $text);
        $this->assertStringNotContainsString('&amp;', $text);
    }

    public function test_account_event_templates_include_relevant_calls_to_action(): void
    {
        config(['app.frontend_url' => 'https://frontend.example']);
        foreach (['password_changed', 'invitation', 'trial_reminder', 'expiry_reminder'] as $event) {
            $data = app(NotificationService::class)->message($event, 'Owner', ['url' => 'https://frontend.example/billing']);
            $this->assertNotEmpty($data['subject']);
            $this->assertSame('Owner', $data['recipient_name']);
            $this->assertSame('https://frontend.example/billing', $data['action_url']);
        }
    }
}
