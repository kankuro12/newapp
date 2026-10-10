<?php

namespace Tests\Feature;

use App\Service\GatewayService;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class InternationalGatewayTest extends TestCase
{
    private function attempt(string $gateway): object
    {
        config(['billing.environment' => 'sandbox', 'billing.stripe.secret' => 'sk_test_secret', 'billing.paypal.client_id' => 'client', 'billing.paypal.secret' => 'secret', 'billing.paypal.merchant_id' => 'MERCHANT']);
        Http::preventStrayRequests();

        return (object) ['gateway' => $gateway, 'reference' => 'payment-reference', 'billing_account_id' => 1, 'currency' => 'USD', 'amount_minor' => '10001', 'provider_id' => $gateway === 'stripe' ? 'cs_test_stored' : 'ORDER123', 'package_snapshot' => json_encode(['name' => 'Package'])];
    }

    private function stripeSession(): array
    {
        return ['id' => 'cs_test_stored', 'client_reference_id' => 'payment-reference', 'mode' => 'payment', 'status' => 'complete', 'payment_status' => 'paid', 'currency' => 'usd', 'amount_total' => 10001, 'payment_intent' => 'pi_verified', 'livemode' => false];
    }

    private function paypalOrder(string $status = 'COMPLETED'): array
    {
        return ['id' => 'ORDER123', 'intent' => 'CAPTURE', 'status' => $status, 'purchase_units' => [
            ['reference_id' => 'payment-reference', 'custom_id' => 'payment-reference', 'payee' => ['merchant_id' => 'MERCHANT'], 'amount' => ['currency_code' => 'USD', 'value' => '100.01'],
                'payments' => ['captures' => [['id' => 'CAPTURE123', 'status' => 'COMPLETED', 'final_capture' => true, 'amount' => ['currency_code' => 'USD', 'value' => '100.01']]]]],
        ]];
    }

    public function test_stripe_uses_stored_session_and_canonical_paid_amount(): void
    {
        $attempt = $this->attempt('stripe');
        Http::fake(['https://api.stripe.com/v1/checkout/sessions/cs_test_stored' => Http::response($this->stripeSession())]);
        $result = app(GatewayService::class)->verify($attempt, ['session_id' => 'forged']);
        $this->assertSame('verified', $result['status']);
        $this->assertSame('10001', $result['amount_minor']);
        $this->assertSame('pi_verified', $result['provider_transaction_id']);
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer sk_test_secret') && str_ends_with($request->url(), '/cs_test_stored'));
    }

    public function test_stripe_unpaid_and_expired_sessions_cannot_activate(): void
    {
        $attempt = $this->attempt('stripe');
        Http::fake(['https://api.stripe.com/*' => Http::response([...$this->stripeSession(), 'payment_status' => 'unpaid'])]);
        $this->assertSame('pending', app(GatewayService::class)->verify($attempt, ['status' => 'paid'])['status']);
    }

    public function test_stripe_initiation_carries_reference_and_idempotency_key(): void
    {
        $attempt = $this->attempt('stripe');
        Http::fake(['https://api.stripe.com/v1/checkout/sessions' => Http::response(['id' => 'cs_test_stored', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_stored', 'livemode' => false])]);
        $result = app(GatewayService::class)->initiate($attempt);
        $this->assertSame('cs_test_stored', $result['provider_id']);
        Http::assertSent(fn ($request) => $request->hasHeader('Idempotency-Key', 'payment-reference') && $request['client_reference_id'] === 'payment-reference' && (string) $request['line_items'][0]['price_data']['unit_amount'] === '10001');
    }

    public function test_paypal_requires_authenticated_completed_capture_and_merchant(): void
    {
        $attempt = $this->attempt('paypal');
        Http::fake(['*/v1/oauth2/token' => Http::response(['access_token' => 'oauth-token']), '*/v2/checkout/orders/ORDER123' => Http::response($this->paypalOrder())]);
        $result = app(GatewayService::class)->verify($attempt, ['token' => 'forged']);
        $this->assertSame('verified', $result['status']);
        $this->assertSame('10001', $result['amount_minor']);
        $this->assertSame('CAPTURE123', $result['provider_transaction_id']);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/ORDER123') && $request->hasHeader('Authorization', 'Bearer oauth-token'));
    }

    public function test_paypal_approved_order_captures_with_stable_request_id(): void
    {
        $attempt = $this->attempt('paypal');
        Http::fake(['*/v1/oauth2/token' => Http::response(['access_token' => 'oauth-token']), '*/v2/checkout/orders/ORDER123' => Http::sequence()->push($this->paypalOrder('APPROVED'))->push($this->paypalOrder()), '*/v2/checkout/orders/ORDER123/capture' => Http::response(['id' => 'ORDER123', 'status' => 'COMPLETED'])]);
        $this->assertSame('verified', app(GatewayService::class)->verify($attempt, [])['status']);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/capture') && $request->hasHeader('PayPal-Request-Id', 'payment-reference-capture'));
    }

    public function test_paypal_foreign_merchant_is_rejected_before_capture(): void
    {
        $attempt = $this->attempt('paypal');
        $order = $this->paypalOrder('APPROVED');
        $order['purchase_units'][0]['payee']['merchant_id'] = 'FOREIGN';
        Http::fake(['*/v1/oauth2/token' => Http::response(['access_token' => 'oauth-token']), '*/v2/checkout/orders/ORDER123' => Http::response($order)]);
        try {
            app(GatewayService::class)->verify($attempt, []);
            $this->fail('Foreign merchant accepted.');
        } catch (HttpException $error) {
            $this->assertSame(422, $error->getStatusCode());
        }
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/capture'));
    }

    public function test_paypal_create_uses_fixed_currency_price_merchant_and_idempotency(): void
    {
        $attempt = $this->attempt('paypal');
        Http::fake(['*/v1/oauth2/token' => Http::response(['access_token' => 'oauth-token']), '*/v2/checkout/orders' => Http::response(['id' => 'ORDER123', 'links' => [['rel' => 'payer-action', 'href' => 'https://www.sandbox.paypal.com/checkoutnow?token=ORDER123']]])]);
        $result = app(GatewayService::class)->initiate($attempt);
        $this->assertSame('ORDER123', $result['provider_id']);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/v2/checkout/orders') && $request->hasHeader('PayPal-Request-Id', 'payment-reference') && $request['purchase_units'][0]['amount'] === ['currency_code' => 'USD', 'value' => '100.01'] && $request['purchase_units'][0]['payee']['merchant_id'] === 'MERCHANT');
    }

    public function test_stripe_foreign_reference_and_environment_are_rejected(): void
    {
        $attempt = $this->attempt('stripe');
        foreach ([['client_reference_id' => 'foreign'], ['livemode' => true]] as $difference) {
            Http::fake(['https://api.stripe.com/*' => Http::response([...$this->stripeSession(), ...$difference])]);
            try {
                app(GatewayService::class)->verify($attempt, []);
                $this->fail('Foreign payment accepted.');
            } catch (HttpException $error) {
                $this->assertSame(422, $error->getStatusCode());
            }
        }
    }

    public function test_paypal_npr_rejected_without_conversion_or_http_request(): void
    {
        $attempt = $this->attempt('paypal');
        $attempt->currency = 'NPR';
        $this->assertFalse(app(GatewayService::class)->supported('paypal', 'NPR'));
        try {
            app(GatewayService::class)->initiate($attempt);
            $this->fail('Unsupported currency accepted.');
        } catch (HttpException $error) {
            $this->assertSame(422, $error->getStatusCode());
        }
        Http::assertNothingSent();
    }
}
