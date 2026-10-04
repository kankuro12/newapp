<?php

require __DIR__.'/../backend/vendor/autoload.php';
$app = require __DIR__.'/../backend/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$database = Illuminate\Support\Facades\DB::connection()->getDatabaseName();
if (config('database.default') !== 'mysql' || $database !== 'business_book') {
    throw new RuntimeException('Refusing migration outside the explicitly named own development database.');
}
echo 'Own development database: '.$database.PHP_EOL;
$status = Illuminate\Support\Facades\Artisan::call('migrate', [
    '--path' => [
        'database/migrations/2026_10_04_043511_create_bill_first_fulfilment_sources.php',
        'database/migrations/2026_10_04_051325_add_phone_to_users_table.php',
    ],
    '--force' => true,
]);
echo Illuminate\Support\Facades\Artisan::output();
exit($status);
