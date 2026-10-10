<?php

namespace Tests\Feature;

use App\Service\GatewayService;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class EsewaGatewayTest extends TestCase
{
    private function attempt(): object
    {
        config(['billing.environment' => 'sandbox', 'billing.esewa.product_code' => 'TEST', 'billing.esewa.secret' => 'test-secret']);
        Http::preventStrayRequests();

        return (object) ['gateway' => 'esewa', 'reference' => 'payment-reference', 'billing_account_id' => 1, 'currency' => 'NPR', 'amount_minor' => '10001', 'provider_id' => 'TEST'];
    }

    private function signedCallback(object $attempt): array
    {
        $fields = ['transaction_code' => 'esewa-transaction', 'status' => 'COMPLETE', 'total_amount' => '100.01', 'transaction_uuid' => $attempt->reference, 'product_code' => 'TEST', 'signed_field_names' => 'transaction_code,status,total_amount,transaction_uuid,product_code,signed_field_names'];
        $message = implode(',', array_map(fn ($name) => $name.'='.$fields[$name], explode(',', $fields['signed_field_names'])));
        $fields['signature'] = base64_encode(hash_hmac('sha256', $message, 'test-secret', true));

        return ['data' => base64_encode(json_encode($fields))];
    }

    public function test_checkout_signs_ordered_exact_amount_and_merchant_fields(): void
    {
        $attempt = $this->attempt();
        $result = app(GatewayService::class)->initiate($attempt);
        $this->assertSame('TEST', $result['provider_id']);
        $this->assertSame('form', $result['checkout']['type']);
        $fields = $result['checkout']['fields'];
        $this->assertSame('100.01', $fields['total_amount']);
        $this->assertSame(base64_encode(hash_hmac('sha256', 'total_amount=100.01,transaction_uuid=payment-reference,product_code=TEST', 'test-secret', true)), $fields['signature']);
        $this->assertFalse(app(GatewayService::class)->supported('esewa', 'USD'));
        Http::assertNothingSent();
    }

    public function test_signed_callback_still_requires_independent_complete_status(): void
    {
        $attempt = $this->attempt();
        Http::fake(['*/transaction/status/*' => Http::response('{"status":"PENDING","totalAmount":100.01,"scd":"TEST","pid":"payment-reference","refId":null}')]);
        $this->assertSame('pending', app(GatewayService::class)->verify($attempt, $this->signedCallback($attempt))['status']);
        Http::assertSent(fn ($request) => $request['transaction_uuid'] === $attempt->reference && $request['total_amount'] === '100.01' && $request['product_code'] === 'TEST');
    }

    public function test_canonical_status_preserves_numeric_decimal_without_float(): void
    {
        $attempt = $this->attempt();
        $attempt->amount_minor = '999999999999';
        Http::fake(['*/transaction/status/*' => Http::response('{"status":"COMPLETE","totalAmount":9999999999.99,"scd":"TEST","pid":"payment-reference","refId":"esewa-transaction"}')]);
        $result = app(GatewayService::class)->verify($attempt, []);
        $this->assertSame('verified', $result['status']);
        $this->assertSame('999999999999', $result['amount_minor']);
        $this->assertSame('esewa-transaction', $result['provider_transaction_id']);
    }

    public function test_tampered_callback_signature_is_rejected_before_lookup(): void
    {
        $attempt = $this->attempt();
        $callback = $this->signedCallback($attempt);
        $fields = json_decode(base64_decode($callback['data']), true);
        $fields['total_amount'] = '1.00';
        try {
            app(GatewayService::class)->verify($attempt, ['data' => base64_encode(json_encode($fields))]);
            $this->fail('Tampered signature accepted.');
        } catch (HttpException $error) {
            $this->assertSame(422, $error->getStatusCode());
        }
        Http::assertNothingSent();
    }

    public function test_wrong_merchant_and_subpaisa_decimal_cannot_verify(): void
    {
        $attempt = $this->attempt();
        foreach ([
            '{"status":"COMPLETE","totalAmount":100.01,"scd":"OTHER","pid":"payment-reference","refId":"txn"}',
            '{"status":"COMPLETE","totalAmount":100.001,"scd":"TEST","pid":"payment-reference","refId":"txn"}',
        ] as $response) {
            Http::fake(['*/transaction/status/*' => Http::response($response)]);
            try {
                app(GatewayService::class)->verify($attempt, []);
                $this->fail('Invalid provider response accepted.');
            } catch (HttpException $error) {
                $this->assertSame(422, $error->getStatusCode());
            }
        }
    }
}
