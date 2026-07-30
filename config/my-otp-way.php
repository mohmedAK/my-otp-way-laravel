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
        //
        // The fallback below is deliberately not a real template name: template
        // names are approved per account (check /dashboard/templates), and a
        // plausible-looking default like "verify_code" reads as though it were
        // guaranteed to exist. It is not. An unset MY_OTP_WAY_TEMPLATE should
        // fail loudly with an obvious placeholder in the log, not quietly with
        // a name a developer might assume is real.
        'template' => env('MY_OTP_WAY_TEMPLATE', 'REPLACE_WITH_YOUR_APPROVED_TEMPLATE_NAME'),
        'language' => env('MY_OTP_WAY_LANGUAGE', 'ar'),
        'channel'  => env('MY_OTP_WAY_CHANNEL', 'whatsapp'),

        // One counter per route, per IP (see ProxyThrottle). Raising `send`
        // does not spend any of `resend`'s or `verify`'s budget, and a user
        // who mistypes a code twice can still resend.
        //
        // Per-route counting means the per-IP budget is the SUM, so these
        // values allow 8 paid messages a minute (send + resend), not 5. That
        // is deliberate: this throttle is defence layer 2, not the spend cap.
        // `resend_cooldown_seconds` bounds spend per number, and the platform's
        // own per-recipient limits and the wallet balance bound it per account.
        // Lower `resend` only if you also accept refusing a human's second tap.
        'throttle' => [
            'send'   => '5,1',
            'resend' => '3,1',
            'verify' => '10,1',
        ],

        'resend_cooldown_seconds'  => 60,
        'allowed_country_prefixes' => ['+964'],
    ],
];
