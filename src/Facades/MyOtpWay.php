<?php

declare(strict_types=1);

namespace MyOtpWay\Laravel\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \MyOtpWay\Laravel\MyOtpWayManager
 */
class MyOtpWay extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'my-otp-way';
    }
}
