<?php

namespace App\Service;

use Illuminate\Support\Facades\Http;

class GatewayService
{
    public function supported(string $gateway, string $currency): bool
    {
        return match ($gateway) {
            'esewa', 'khalti' => $currency === 'NPR',
            'stripe' => in_array($currency, ['NPR', 'USD', 'EUR'], true),
            'paypal' => in_array($currency, ['USD', 'EUR'], true),
            default => false,
        };
    }

    public function ready(string $gateway): bool
    {
        if (! in_array(config('billing.environment'), ['sandbox', 'production'], true)) {
            return false;
        }
        $present = fn ($key) => is_string(config($key)) && config($key) !== '';

        return match ($gateway) {
            'esewa' => $present('billing.esewa.secret') && $present('billing.esewa.product_code') && (bool) preg_match('/^[A-Za-z0-9-]+$/D', config('billing.esewa.product_code')),
            'khalti' => $present('billing.khalti.secret'),
            'stripe' => $present('billing.stripe.secret') && str_starts_with(config('billing.stripe.secret'), config('billing.environment') === 'production' ? 'sk_live_' : 'sk_test_'),
            'paypal' => $present('billing.paypal.client_id') && $present('billing.paypal.secret') && $present('billing.paypal.merchant_id'),
            default => false,
        };
    }

    public function available(): array
    {
        $available = [];
        foreach (['esewa', 'khalti', 'stripe', 'paypal'] as $gateway) {
            if ($this->ready($gateway)) {
                $available[] = ['id' => $gateway, 'currencies' => array_values(array_filter(['NPR', 'USD', 'EUR'], fn ($currency) => $this->supported($gateway, $currency)))];
            }
        }

        return $available;
    }

    private function environment(): string
    {
        $environment = config('billing.environment');
        abort_unless(in_array($environment, ['sandbox', 'production'], true), 503, 'Payment environment unavailable.');

        return $environment;
    }

    private function returnUrl(object $attempt): string
    {
        return rtrim(config('app.frontend_url') ?: config('app.url'), '/').'/billing?'.http_build_query(['account' => $attempt->billing_account_id, 'payment' => $attempt->reference]);
    }

    private function redirect(string $url, string $host): string
    {
        $parts = parse_url($url);
        abort_unless(is_array($parts) && ($parts['scheme'] ?? '') === 'https' && ($parts['host'] ?? '') === $host && ! isset($parts['user']) && ! isset($parts['pass']) && ! isset($parts['port']), 503, 'Payment checkout address unavailable.');

        return $url;
    }

    private function stripeRequest(string $method, string $path, array $payload = [], ?string $key = null): array
    {
        $secret = config('billing.stripe.secret');
        $prefix = $this->environment() === 'production' ? 'sk_live_' : 'sk_test_';
        abort_unless(is_string($secret) && str_starts_with($secret, $prefix), 503, 'Stripe is not configured for this environment.');
        $http = Http::withToken($secret)->acceptJson()->timeout(20)->connectTimeout(5)->withOptions(['allow_redirects' => false]);
        if ($key) {
            $http = $http->withHeaders(['Idempotency-Key' => $key]);
        }
        if (config('billing.stripe.api_version')) {
            $http = $http->withHeaders(['Stripe-Version' => config('billing.stripe.api_version')]);
        }
        $url = 'https://api.stripe.com/v1/'.$path;
        $response = $method === 'POST' ? $http->asForm()->post($url, $payload) : $http->get($url, $payload);
        abort_unless($response->successful(), 503, 'Stripe request unavailable.');
        $data = $response->json();
        abort_unless(is_array($data), 503, 'Stripe response unavailable.');

        return $data;
    }

    private function initiateStripe(object $attempt): array
    {
        abort_unless($this->supported('stripe', $attempt->currency), 422, 'Payment currency unavailable.');
        $this->decimalAmount((string) $attempt->amount_minor);
        $snapshot = json_decode($attempt->package_snapshot, true, 512, JSON_THROW_ON_ERROR);
        $data = $this->stripeRequest('POST', 'checkout/sessions', [
            'mode' => 'payment', 'client_reference_id' => $attempt->reference, 'success_url' => $this->returnUrl($attempt), 'cancel_url' => $this->returnUrl($attempt),
            'payment_method_types' => ['card'], 'metadata' => ['payment_reference' => $attempt->reference],
            'line_items' => [['quantity' => 1, 'price_data' => ['currency' => strtolower($attempt->currency), 'unit_amount' => (string) $attempt->amount_minor, 'product_data' => ['name' => $snapshot['name']]]]],
        ], $attempt->reference);
        abort_unless(is_string($data['id'] ?? null) && preg_match('/^cs_[A-Za-z0-9_]+$/D', $data['id']) && is_string($data['url'] ?? null) && ($data['livemode'] ?? null) === ($this->environment() === 'production'), 503, 'Stripe checkout unavailable.');

        return ['provider_id' => $data['id'], 'checkout' => ['type' => 'redirect', 'url' => $this->redirect($data['url'], 'checkout.stripe.com')]];
    }

    private function verifyStripe(object $attempt): array
    {
        abort_unless($this->supported('stripe', $attempt->currency), 422, 'Payment currency unavailable.');
        abort_unless(is_string($attempt->provider_id) && preg_match('/^cs_[A-Za-z0-9_]+$/D', $attempt->provider_id), 409, 'Payment identifier unavailable.');
        $data = $this->stripeRequest('GET', 'checkout/sessions/'.$attempt->provider_id);
        abort_unless(($data['id'] ?? null) === $attempt->provider_id && ($data['client_reference_id'] ?? null) === $attempt->reference && ($data['mode'] ?? null) === 'payment' && ($data['livemode'] ?? null) === ($this->environment() === 'production'), 422, 'Payment identifier mismatch.');
        if (($data['status'] ?? '') !== 'complete' || ($data['payment_status'] ?? '') !== 'paid') {
            return ['status' => ($data['status'] ?? '') === 'expired' ? 'failed' : 'pending'];
        }
        $amount = $data['amount_total'] ?? null;
        $transaction = $data['payment_intent'] ?? null;
        abort_unless((is_int($amount) || is_string($amount)) && preg_match('/^[1-9][0-9]{0,11}$/D', (string) $amount) && is_string($transaction) && preg_match('/^pi_[A-Za-z0-9_]+$/D', $transaction) && is_string($data['currency'] ?? null), 422, 'Payment verification incomplete.');

        return ['status' => 'verified', 'reference' => $attempt->reference, 'provider_transaction_id' => $transaction, 'amount_minor' => (string) $amount, 'currency' => strtoupper($data['currency'])];
    }

    private function paypalBase(): string
    {
        return $this->environment() === 'production' ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';
    }

    private function paypalToken(): string
    {
        $client = config('billing.paypal.client_id');
        $secret = config('billing.paypal.secret');
        $merchant = config('billing.paypal.merchant_id');
        abort_unless(is_string($client) && $client !== '' && is_string($secret) && $secret !== '' && is_string($merchant) && $merchant !== '', 503, 'PayPal is not configured.');
        $response = Http::withBasicAuth($client, $secret)->asForm()->acceptJson()->timeout(20)->connectTimeout(5)->withOptions(['allow_redirects' => false])->post($this->paypalBase().'/v1/oauth2/token', ['grant_type' => 'client_credentials']);
        abort_unless($response->successful(), 503, 'PayPal authentication unavailable.');
        $token = $response->json('access_token');
        abort_unless(is_string($token) && $token !== '', 503, 'PayPal authentication unavailable.');

        return $token;
    }

    private function paypalRequest(string $token, string $method, string $path, array $payload = [], ?string $key = null): array
    {
        $http = Http::withToken($token)->acceptJson()->withHeaders(['Prefer' => 'return=representation'])->timeout(20)->connectTimeout(5)->withOptions(['allow_redirects' => false]);
        if ($key) {
            $http = $http->withHeaders(['PayPal-Request-Id' => $key]);
        }
        $url = $this->paypalBase().'/v2/checkout/orders'.$path;
        $response = $method === 'POST' ? $http->send('POST', $url, ['json' => (object) $payload]) : $http->get($url);
        abort_unless($response->successful(), 503, 'PayPal request unavailable.');
        $data = $response->json();
        abort_unless(is_array($data), 503, 'PayPal response unavailable.');

        return $data;
    }

    private function initiatePaypal(object $attempt): array
    {
        abort_unless($this->supported('paypal', $attempt->currency), 422, 'Payment currency unavailable.');
        $amount = $this->decimalAmount((string) $attempt->amount_minor);
        $data = $this->paypalRequest($this->paypalToken(), 'POST', '', [
            'intent' => 'CAPTURE', 'purchase_units' => [['reference_id' => $attempt->reference, 'custom_id' => $attempt->reference,
                'payee' => ['merchant_id' => config('billing.paypal.merchant_id')], 'amount' => ['currency_code' => $attempt->currency, 'value' => $amount]]],
            'payment_source' => ['paypal' => ['experience_context' => ['return_url' => $this->returnUrl($attempt), 'cancel_url' => $this->returnUrl($attempt), 'user_action' => 'PAY_NOW', 'shipping_preference' => 'NO_SHIPPING']]],
        ], $attempt->reference);
        abort_unless(is_string($data['id'] ?? null) && preg_match('/^[A-Za-z0-9]+$/D', $data['id']), 503, 'PayPal checkout unavailable.');
        $link = collect($data['links'] ?? [])->first(fn ($link) => in_array($link['rel'] ?? '', ['payer-action', 'approve'], true));
        abort_unless(is_array($link) && is_string($link['href'] ?? null), 503, 'PayPal checkout unavailable.');
        $host = $this->environment() === 'production' ? 'www.paypal.com' : 'www.sandbox.paypal.com';

        return ['provider_id' => $data['id'], 'checkout' => ['type' => 'redirect', 'url' => $this->redirect($link['href'], $host)]];
    }

    private function paypalUnit(object $attempt, array $data): array
    {
        $units = $data['purchase_units'] ?? [];
        abort_unless(($data['id'] ?? '') === $attempt->provider_id && ($data['intent'] ?? '') === 'CAPTURE' && is_array($units) && count($units) === 1, 422, 'Payment identifier mismatch.');
        $unit = $units[0];
        abort_unless(($unit['reference_id'] ?? null) === $attempt->reference && ($unit['custom_id'] ?? null) === $attempt->reference && ($unit['payee']['merchant_id'] ?? null) === config('billing.paypal.merchant_id'), 422, 'Payment merchant or reference mismatch.');
        abort_unless(($unit['amount']['currency_code'] ?? null) === $attempt->currency && is_string($unit['amount']['value'] ?? null) && $this->minorAmount($unit['amount']['value']) === (string) $attempt->amount_minor, 422, 'Payment amount mismatch.');

        return $unit;
    }

    private function verifyPaypal(object $attempt): array
    {
        abort_unless($this->supported('paypal', $attempt->currency), 422, 'Payment currency unavailable.');
        abort_unless(is_string($attempt->provider_id) && preg_match('/^[A-Za-z0-9]+$/D', $attempt->provider_id), 409, 'Payment identifier unavailable.');
        $token = $this->paypalToken();
        $data = $this->paypalRequest($token, 'GET', '/'.$attempt->provider_id);
        $unit = $this->paypalUnit($attempt, $data);
        if (($data['status'] ?? '') === 'APPROVED') {
            $this->paypalRequest($token, 'POST', '/'.$attempt->provider_id.'/capture', [], $attempt->reference.'-capture');
            $data = $this->paypalRequest($token, 'GET', '/'.$attempt->provider_id);
            $unit = $this->paypalUnit($attempt, $data);
        }
        if (($data['status'] ?? '') !== 'COMPLETED') {
            return ['status' => ($data['status'] ?? '') === 'VOIDED' ? 'failed' : 'pending'];
        }
        $captures = $unit['payments']['captures'] ?? [];
        abort_unless(is_array($captures) && count($captures) === 1, 422, 'Payment capture incomplete.');
        $capture = $captures[0];
        if (($capture['status'] ?? '') !== 'COMPLETED') {
            return ['status' => in_array($capture['status'] ?? '', ['REFUNDED', 'PARTIALLY_REFUNDED'], true) ? 'refunded' : 'pending'];
        }
        abort_unless(is_string($capture['id'] ?? null) && preg_match('/^[A-Za-z0-9]+$/D', $capture['id']) && ($capture['final_capture'] ?? null) === true && ($capture['amount']['currency_code'] ?? null) === $attempt->currency && is_string($capture['amount']['value'] ?? null), 422, 'Payment capture incomplete.');

        return ['status' => 'verified', 'reference' => $attempt->reference, 'provider_transaction_id' => $capture['id'], 'amount_minor' => $this->minorAmount($capture['amount']['value']), 'currency' => $attempt->currency];
    }

    private function esewaSettings(): array
    {
        $secret = config('billing.esewa.secret');
        $product = config('billing.esewa.product_code');
        $environment = config('billing.environment');
        abort_unless(is_string($secret) && $secret !== '' && is_string($product) && preg_match('/^[A-Za-z0-9-]+$/D', $product), 503, 'eSewa is not configured.');
        abort_unless(in_array($environment, ['sandbox', 'production'], true), 503, 'Payment environment unavailable.');

        return [$secret, $product, $environment];
    }

    private function decimalAmount(string $minor): string
    {
        abort_unless(preg_match('/^[1-9][0-9]{0,11}$/D', $minor), 422, 'Payment amount unavailable.');
        $padded = str_pad($minor, 3, '0', STR_PAD_LEFT);

        return substr($padded, 0, -2).'.'.substr($padded, -2);
    }

    private function minorAmount(string $decimal): string
    {
        abort_unless(preg_match('/^(?:0|[1-9][0-9]*|[1-9][0-9]{0,2}(?:,[0-9]{3})+)(?:\.[0-9]{1,2})?$/D', $decimal), 422, 'Payment decimal amount invalid.');
        $parts = explode('.', str_replace(',', '', $decimal));
        $minor = ltrim($parts[0].str_pad($parts[1] ?? '', 2, '0'), '0');
        abort_unless(preg_match('/^[1-9][0-9]{0,11}$/D', $minor), 422, 'Payment amount unavailable.');

        return $minor;
    }

    private function exactJson(string $json): array
    {
        // Preserve provider decimal lexemes before JSON decoding can turn them into floats.
        $quoted = preg_replace_callback('/"(?:[^"\\\\]|\\\\.)*"|-?[0-9]+(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?/', fn ($match) => $match[0][0] === '"' ? $match[0] : '"'.$match[0].'"', $json);
        try {
            $data = json_decode($quoted, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            abort(422, 'Payment response invalid.');
        }
        abort_unless(is_array($data), 422, 'Payment response invalid.');

        return $data;
    }

    private function initiateEsewa(object $attempt): array
    {
        abort_unless($attempt->currency === 'NPR', 422, 'Payment currency unavailable.');
        [$secret, $product, $environment] = $this->esewaSettings();
        $amount = $this->decimalAmount((string) $attempt->amount_minor);
        $returnUrl = rtrim(config('app.frontend_url') ?: config('app.url'), '/').'/billing?'.http_build_query(['account' => $attempt->billing_account_id, 'payment' => $attempt->reference]);
        $fields = ['amount' => $amount, 'tax_amount' => '0', 'total_amount' => $amount, 'transaction_uuid' => $attempt->reference,
            'product_code' => $product, 'product_service_charge' => '0', 'product_delivery_charge' => '0', 'success_url' => $returnUrl, 'failure_url' => $returnUrl,
            'signed_field_names' => 'total_amount,transaction_uuid,product_code',
            'signature' => base64_encode(hash_hmac('sha256', 'total_amount='.$amount.',transaction_uuid='.$attempt->reference.',product_code='.$product, $secret, true))];
        $url = $environment === 'production' ? 'https://epay.esewa.com.np/api/epay/main/v2/form' : 'https://rc-epay.esewa.com.np/api/epay/main/v2/form';

        return ['provider_id' => $product, 'checkout' => ['type' => 'form', 'url' => $url, 'fields' => $fields]];
    }

    private function verifyEsewa(object $attempt, array $callback): array
    {
        abort_unless($attempt->currency === 'NPR', 422, 'Payment currency unavailable.');
        [$secret, $product, $environment] = $this->esewaSettings();
        abort_unless($attempt->provider_id === $product, 422, 'Payment merchant mismatch.');
        if (isset($callback['data'])) {
            abort_unless(is_string($callback['data']) && strlen($callback['data']) <= 16000, 422, 'Payment callback invalid.');
            $decoded = base64_decode($callback['data'], true);
            abort_unless($decoded !== false, 422, 'Payment callback invalid.');
            $fields = $this->exactJson($decoded);
            $signed = 'transaction_code,status,total_amount,transaction_uuid,product_code,signed_field_names';
            abort_unless(($fields['signed_field_names'] ?? '') === $signed, 422, 'Payment callback signature fields invalid.');
            $values = [];
            foreach (explode(',', $signed) as $name) {
                abort_unless(is_string($fields[$name] ?? null), 422, 'Payment callback invalid.');
                $values[] = $name.'='.$fields[$name];
            }
            $signature = base64_encode(hash_hmac('sha256', implode(',', $values), $secret, true));
            abort_unless(is_string($fields['signature'] ?? null) && hash_equals($signature, $fields['signature']), 422, 'Payment callback signature invalid.');
            abort_unless($fields['transaction_uuid'] === $attempt->reference && $fields['product_code'] === $product && $this->minorAmount($fields['total_amount']) === (string) $attempt->amount_minor, 422, 'Payment callback mismatch.');
        }
        $url = $environment === 'production' ? 'https://epay.esewa.com.np/api/epay/transaction/status/' : 'https://uat.esewa.com.np/api/epay/transaction/status/';
        $response = Http::acceptJson()->timeout(20)->connectTimeout(5)->withOptions(['allow_redirects' => false])->get($url, ['product_code' => $product, 'total_amount' => $this->decimalAmount((string) $attempt->amount_minor), 'transaction_uuid' => $attempt->reference]);
        abort_unless($response->successful(), 503, 'eSewa verification unavailable.');
        $data = $this->exactJson($response->body());
        abort_unless(($data['pid'] ?? null) === $attempt->reference && ($data['scd'] ?? null) === $product, 422, 'Payment identifier mismatch.');
        if (($data['status'] ?? '') !== 'COMPLETE') {
            return ['status' => match ($data['status'] ?? '') {
                'FULL_REFUND', 'PARTIAL_REFUND' => 'refunded',
                'CANCELED' => 'failed',
                default => 'pending',
            }];
        }
        $transaction = $data['refId'] ?? null;
        abort_unless(is_string($data['totalAmount'] ?? null) && is_string($transaction) && $transaction !== '' && strlen($transaction) <= 255, 422, 'Payment verification incomplete.');
        if (isset($fields)) {
            abort_unless($fields['transaction_code'] === $transaction, 422, 'Payment transaction mismatch.');
        }

        return ['status' => 'verified', 'reference' => $attempt->reference, 'provider_transaction_id' => $transaction, 'amount_minor' => $this->minorAmount($data['totalAmount']), 'currency' => 'NPR'];
    }

    private function khaltiRequest(string $operation, array $payload): array
    {
        $secret = config('billing.khalti.secret');
        abort_unless(is_string($secret) && $secret !== '', 503, 'Khalti is not configured.');
        $environment = config('billing.environment');
        abort_unless(in_array($environment, ['sandbox', 'production'], true), 503, 'Payment environment unavailable.');
        $base = $environment === 'production' ? 'https://khalti.com/api/v2/' : 'https://dev.khalti.com/api/v2/';
        // Initiation is never automatically retried: a timeout may already have created a charge.
        $response = Http::withHeaders(['Authorization' => 'Key '.$secret])->acceptJson()->timeout(20)->connectTimeout(5)->withOptions(['allow_redirects' => false])->post($base.'epayment/'.$operation.'/', $payload);
        abort_unless($response->successful(), 503, 'Khalti request unavailable.');
        $data = $response->json();
        abort_unless(is_array($data), 503, 'Khalti response unavailable.');

        return $data;
    }

    public function initiate(object $attempt): array
    {
        if ($attempt->gateway === 'stripe') {
            return $this->initiateStripe($attempt);
        }
        if ($attempt->gateway === 'paypal') {
            return $this->initiatePaypal($attempt);
        }
        if ($attempt->gateway === 'esewa') {
            return $this->initiateEsewa($attempt);
        }
        abort_unless($this->supported($attempt->gateway, $attempt->currency), 422, 'Payment currency unavailable.');
        $amount = (string) $attempt->amount_minor;
        abort_unless(preg_match('/^[1-9][0-9]{0,11}$/D', $amount) && (int) $amount >= 1000, 422, 'Khalti minimum payment is NPR 10.');
        $snapshot = json_decode($attempt->package_snapshot, true, 512, JSON_THROW_ON_ERROR);
        $website = rtrim(config('app.frontend_url') ?: config('app.url'), '/');
        $data = $this->khaltiRequest('initiate', [
            'return_url' => $website.'/billing?'.http_build_query(['account' => $attempt->billing_account_id, 'payment' => $attempt->reference]),
            'website_url' => $website,
            'amount' => (int) $amount,
            'purchase_order_id' => $attempt->reference,
            'purchase_order_name' => $snapshot['name'],
        ]);
        $pidx = $data['pidx'] ?? null;
        $url = $data['payment_url'] ?? null;
        abort_unless(is_string($pidx) && $pidx !== '' && strlen($pidx) <= 255 && is_string($url), 503, 'Khalti checkout unavailable.');
        $parts = parse_url($url);
        $host = config('billing.environment') === 'production' ? 'pay.khalti.com' : 'test-pay.khalti.com';
        abort_unless(is_array($parts) && ($parts['scheme'] ?? '') === 'https' && ($parts['host'] ?? '') === $host && ! isset($parts['user']) && ! isset($parts['pass']) && ! isset($parts['port']), 503, 'Khalti checkout address unavailable.');

        return ['provider_id' => $pidx, 'checkout' => ['type' => 'redirect', 'url' => $url]];
    }

    public function verify(object $attempt, array $callback): array
    {
        if ($attempt->gateway === 'stripe') {
            return $this->verifyStripe($attempt);
        }
        if ($attempt->gateway === 'paypal') {
            return $this->verifyPaypal($attempt);
        }
        if ($attempt->gateway === 'esewa') {
            return $this->verifyEsewa($attempt, $callback);
        }
        abort_unless($this->supported($attempt->gateway, $attempt->currency), 422, 'Payment currency unavailable.');
        abort_unless(is_string($attempt->provider_id) && $attempt->provider_id !== '', 409, 'Payment identifier unavailable.');
        $data = $this->khaltiRequest('lookup', ['pidx' => $attempt->provider_id]);
        abort_unless(($data['pidx'] ?? null) === $attempt->provider_id, 422, 'Payment identifier mismatch.');
        if (($data['status'] ?? null) !== 'Completed') {
            return ['status' => match ($data['status'] ?? '') {
                'Refunded', 'Partially Refunded' => 'refunded',
                'Expired', 'User canceled' => 'failed',
                default => 'pending',
            }];
        }
        $amount = $data['total_amount'] ?? null;
        $transaction = $data['transaction_id'] ?? null;
        abort_unless((is_int($amount) || is_string($amount)) && preg_match('/^[1-9][0-9]{0,11}$/D', (string) $amount) && is_string($transaction) && $transaction !== '' && strlen($transaction) <= 255, 422, 'Payment verification incomplete.');

        return ['status' => 'verified', 'reference' => $attempt->reference, 'provider_transaction_id' => $transaction, 'amount_minor' => (string) $amount, 'currency' => 'NPR'];
    }
}
