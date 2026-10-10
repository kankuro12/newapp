<?php

namespace Tests\Feature;

use App\Service\GatewayService;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class KhaltiGatewayTest extends TestCase
{
    private function attempt(): object
    {
        config(['billing.environment' => 'sandbox', 'billing.khalti.secret' => 'test-secret']);
        Http::preventStrayRequests();

        return (object) ['gateway' => 'khalti', 'reference' => 'payment-reference', 'billing_account_id' => 1, 'currency' => 'NPR', 'amount_minor' => 10001, 'provider_id' => 'stored-pidx', 'package_snapshot' => json_encode(['name' => 'Package'])];
    }

    public function test_lookup_uses_stored_identifier_and_authenticated_integer_amount(): void
    {
        $attempt = $this->attempt();
        Http::fake(['*/epayment/lookup/' => Http::response(['pidx' => 'stored-pidx', 'status' => 'Completed', 'total_amount' => 10001, 'transaction_id' => 'transaction-1'])]);
        $result = app(GatewayService::class)->verify($attempt, ['pidx' => 'forged', 'status' => 'Completed']);
        $this->assertSame('verified', $result['status']);
        $this->assertSame('10001', $result['amount_minor']);
        Http::assertSent(fn ($request) => $request['pidx'] === 'stored-pidx' && $request->hasHeader('Authorization', 'Key test-secret'));
        $this->assertFalse(app(GatewayService::class)->supported('khalti', 'USD'));
    }

    public function test_lookup_rejects_wrong_identifier_and_fractional_paisa(): void
    {
        $attempt = $this->attempt();
        foreach ([
            ['pidx' => 'other-pidx', 'status' => 'Completed', 'total_amount' => 10001, 'transaction_id' => 'txn'],
            ['pidx' => 'stored-pidx', 'status' => 'Completed', 'total_amount' => 10001.5, 'transaction_id' => 'txn'],
        ] as $response) {
            Http::fake(['*/epayment/lookup/' => Http::response($response)]);
            try {
                app(GatewayService::class)->verify($attempt, []);
                $this->fail('Invalid provider response accepted.');
            } catch (HttpException $error) {
                $this->assertSame(422, $error->getStatusCode());
            }
        }
    }

    public function test_checkout_rejects_external_redirect(): void
    {
        $attempt = $this->attempt();
        Http::fake(['*/epayment/initiate/' => Http::response(['pidx' => 'new-pidx', 'payment_url' => 'https://attacker.example/pay'])]);
        $this->expectException(HttpException::class);
        app(GatewayService::class)->initiate($attempt);
    }

    public function test_pending_lookup_cannot_be_upgraded_by_callback(): void
    {
        $attempt = $this->attempt();
        Http::fake(['*/epayment/lookup/' => Http::response(['pidx' => 'stored-pidx', 'status' => 'Pending', 'total_amount' => 10001, 'transaction_id' => null])]);
        $this->assertSame('pending', app(GatewayService::class)->verify($attempt, ['status' => 'Completed'])['status']);
    }

    public function test_initiation_sends_frozen_price_and_returns_provider_redirect(): void
    {
        $attempt = $this->attempt();
        Http::fake(['*/epayment/initiate/' => Http::response(['pidx' => 'new-pidx', 'payment_url' => 'https://test-pay.khalti.com/pay/new-pidx'])]);
        $result = app(GatewayService::class)->initiate($attempt);
        $this->assertSame('new-pidx', $result['provider_id']);
        $this->assertSame('redirect', $result['checkout']['type']);
        Http::assertSent(fn ($request) => $request['amount'] === 10001 && $request['purchase_order_id'] === $attempt->reference);
    }
}
