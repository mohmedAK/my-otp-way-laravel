<?php

declare(strict_types=1);

namespace MyOtpWay\Laravel\Tests;

use MyOtpWay\Laravel\MyOtpWayServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [MyOtpWayServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('my-otp-way.base_url', 'https://api.test/v1');
        $app['config']->set('my-otp-way.api_key', 'test-key');
    }
}
