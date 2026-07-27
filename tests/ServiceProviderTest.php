<?php

declare(strict_types=1);

namespace MyOtpWay\Laravel\Tests;

use MyOtpWay\Laravel\MyOtpWayManager;

class ServiceProviderTest extends TestCase
{
    public function test_it_binds_the_manager_as_a_singleton(): void
    {
        $this->assertInstanceOf(MyOtpWayManager::class, $this->app->make('my-otp-way'));
        $this->assertSame($this->app->make('my-otp-way'), $this->app->make('my-otp-way'));
    }

    public function test_it_merges_the_default_config(): void
    {
        $this->assertSame('my-otp', config('my-otp-way.proxy.prefix'));
        $this->assertSame(['+964'], config('my-otp-way.proxy.allowed_country_prefixes'));
        $this->assertSame(60, config('my-otp-way.proxy.resend_cooldown_seconds'));
    }
}
