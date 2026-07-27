<?php

declare(strict_types=1);

return [
    'base_url' => env('MY_OTP_WAY_URL', 'https://myotpway.online/api/v1'),
    'api_key'  => env('MY_OTP_WAY_KEY'),
    'timeout'  => (int) env('MY_OTP_WAY_TIMEOUT', 10),

    'proxy' => [
        'prefix'     => 'my-otp',
        'middleware' => ['api'],

        // Chosen by the server, never by the mobile client. If the client could
        // name the template it would pick the most expensive one.
        'template' => env('MY_OTP_WAY_TEMPLATE', 'verify_code'),
        'language' => env('MY_OTP_WAY_LANGUAGE', 'ar'),
        'channel'  => env('MY_OTP_WAY_CHANNEL', 'whatsapp'),

        'throttle' => [
            'send'   => '5,1',
            'resend' => '3,1',
            'verify' => '10,1',
        ],

        'resend_cooldown_seconds'  => 60,
        'allowed_country_prefixes' => ['+964'],
    ],
];
