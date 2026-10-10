<?php

use App\Service\NotificationService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('app:clean-cache', function () {
    if (config('cache.default') === 'database') {
        DB::table(config('cache.stores.database.table'))->where('expiration', '<', time())->delete();
        DB::table(config('cache.stores.database.lock_table') ?: 'cache_locks')->where('expiration', '<', time())->delete();
    }
})->purpose('Remove expired entries from this application database cache');
Schedule::command('app:clean-cache')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('app:generate-recurring-expenses')->everyMinute()->withoutOverlapping();
Schedule::command('app:reconcile-subscription-payments --limit=10')->everyFiveMinutes()->withoutOverlapping(15);

Artisan::command('app:dispatch-account-messages', function () {
    $this->info('Dispatched: '.app(NotificationService::class)->dispatchPending(50));
})->purpose('Dispatch committed pending account messages and flag uncertain worker outcomes');
Schedule::command('app:dispatch-account-messages')->everyMinute()->withoutOverlapping(5);
