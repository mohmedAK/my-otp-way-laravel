<?php

declare(strict_types=1);

namespace MyOtpWay\Laravel;

use Illuminate\Support\ServiceProvider;

class MyOtpWayServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/my-otp-way.php', 'my-otp-way');

        $this->app->singleton('my-otp-way', fn ($app) => new MyOtpWayManager($app['config']->get('my-otp-way')));
        $this->app->alias('my-otp-way', MyOtpWayManager::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/my-otp-way.php' => config_path('my-otp-way.php'),
            ], 'my-otp-way-config');
        }
    }
}
