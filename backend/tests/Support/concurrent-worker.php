<?php

use App\Models\Tenant;
use App\NepaliDate;
use App\Service\AccountingService;
use App\Service\AppointmentService;
use App\Service\RecurringExpenseService;
use App\Support\CurrentTenant;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Validation\ValidationException;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (config('database.connections.mysql.database') !== 'business_book_testing') {
    throw new RuntimeException('Test database required.');
}
[$script, $tenant, $actor, $cash, $date, $uuid] = $argv;
app(CurrentTenant::class)->set(Tenant::findOrFail($tenant));
try {
    if (($argv[7] ?? '') === 'appointment') {
        app(AppointmentService::class)->create((int) $actor, ['resource_id' => (int) $argv[6], 'business_date_bs' => $date, 'start_minute' => 600, 'client_name' => 'Concurrent booking', 'status' => 'blocked', 'duration_minutes' => 30], $uuid);
        echo 'booked';
        exit;
    }
    if (isset($argv[6])) {
        $service = app(RecurringExpenseService::class);
        if (($argv[7] ?? '') === 'manual') {
            $service->post((int) $actor, (int) $argv[6], ['mutation_uuid' => $uuid, 'period_bs' => NepaliDate::monthRange((int) $date)[0], 'business_date_bs' => $date, 'expected_total_paisa' => '100', 'paid_now' => '0']);
        } else {
            $service->generateDue((int) $argv[6], (int) $date);
        }
        echo 'recorded';
        exit;
    }
    app(AccountingService::class)->owner((int) $actor, ['kind' => 'withdrawal', 'money_account_id' => $cash, 'business_date_bs' => $date, 'amount' => '2'], $uuid);
    echo 'posted';
} catch (ValidationException $e) {
    echo ($argv[7] ?? '') === 'appointment' ? 'occupied' : 'insufficient';
}
