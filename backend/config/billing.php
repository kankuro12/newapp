<?php

return [
    'environment' => env('BILLING_ENVIRONMENT', 'sandbox'),
    'stripe' => ['secret' => env('STRIPE_SECRET_KEY'), 'api_version' => env('STRIPE_API_VERSION'), 'webhook_secret' => env('STRIPE_WEBHOOK_SECRET')],
    'paypal' => ['client_id' => env('PAYPAL_CLIENT_ID'), 'secret' => env('PAYPAL_CLIENT_SECRET'), 'merchant_id' => env('PAYPAL_MERCHANT_ID')],
    'esewa' => ['product_code' => env('ESEWA_PRODUCT_CODE'), 'secret' => env('ESEWA_SECRET_KEY')],
    'khalti' => ['secret' => env('KHALTI_SECRET_KEY')],
];
