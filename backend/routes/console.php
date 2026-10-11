<?php

use App\Jobs\SendPackageReminders;
use App\Service\BillingService;
use App\Service\CampaignService;
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

Artisan::command('app:apply-subscription-terms', function () {
    $this->info('Applied: '.app(BillingService::class)->applyDueTerms());
})->purpose('Start stacked renewals whose term began and apply their business allowance');
Schedule::command('app:apply-subscription-terms')->everyFiveMinutes()->withoutOverlapping(10);

Artisan::command('app:send-package-reminders', function () {
    SendPackageReminders::dispatch();
    $this->info('Package reminder run queued.');
})->purpose('Queue 7/3/1-day and expiry package reminders by email, FCM and WhatsApp');
Schedule::command('app:send-package-reminders')->dailyAt('09:00')->timezone('Asia/Kathmandu')->withoutOverlapping(30);

Artisan::command('app:dispatch-campaigns', function () {
    $this->info('Campaigns started: '.app(CampaignService::class)->dispatchDue());
})->purpose('Start scheduled marketing campaigns on the queue');
Schedule::command('app:dispatch-campaigns')->everyMinute()->withoutOverlapping(5);
