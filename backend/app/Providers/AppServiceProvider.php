<?php

namespace App\Providers;

use App\Service\NotificationService;
use App\Support\CurrentTenant;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(CurrentTenant::class, fn () => new CurrentTenant);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::listen(Logout::class, function (Logout $event) {
            if ($event->guard === 'tenant' && $event->user) {
                app(NotificationService::class)->revokeForLogout((int) $event->user->getAuthIdentifier(), request()->input('push_device_uuid'));
            }
        });
        ResetPassword::createUrlUsing(fn ($user, $token) => app(NotificationService::class)->resetUrl($user->email, $token));
        ResetPassword::toMailUsing(fn ($user, $token) => app(NotificationService::class)->mailMessage('password_reset', $user->name, ['url' => app(NotificationService::class)->resetUrl($user->email, $token)]));
        VerifyEmail::toMailUsing(fn ($user, $url) => app(NotificationService::class)->mailMessage('verification', $user->name, ['url' => $url]));
    }
}
