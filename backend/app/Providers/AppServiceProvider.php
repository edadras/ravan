<?php

namespace App\Providers;

use App\Services\Payments\PaymentGateway;
use App\Services\Payments\SandboxGateway;
use App\Services\Payments\ZarinpalGateway;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(PaymentGateway::class, fn () => match (config('ravan.payments.gateway')) {
            'zarinpal' => new ZarinpalGateway,
            default => new SandboxGateway,
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
