<?php

declare(strict_types=1);

namespace MyOtpWay\Laravel\Events;

/**
 * Fired after every call to the platform. Carries no recipient and no OTP code:
 * this lands in the host application's logs, and that is their data to decide
 * about, not ours.
 */
final class MyOtpWayRequestSent
{
    public function __construct(
        public readonly string $endpoint,
        public readonly int $httpStatus,
        public readonly int $durationMs,
        public readonly ?string $requestId = null,
    ) {
    }
}
