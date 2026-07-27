<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use MyOtpWay\Laravel\Http\Controllers\OtpProxyController;

Route::prefix((string) config('my-otp-way.proxy.prefix', 'my-otp'))
    ->middleware((array) config('my-otp-way.proxy.middleware', ['api']))
    ->name('my-otp.')
    ->group(function () {
        Route::post('send', [OtpProxyController::class, 'send'])
            ->middleware('throttle:' . config('my-otp-way.proxy.throttle.send', '5,1'))
            ->name('send');

        Route::post('resend', [OtpProxyController::class, 'resend'])
            ->middleware('throttle:' . config('my-otp-way.proxy.throttle.resend', '3,1'))
            ->name('resend');

        Route::post('verify', [OtpProxyController::class, 'verify'])
            ->middleware('throttle:' . config('my-otp-way.proxy.throttle.verify', '10,1'))
            ->name('verify');
    });
