<?php

declare(strict_types=1);

namespace MyOtpWay\Laravel\Exceptions;

class RateLimitException extends MyOtpWayException
{
    public function __construct(
        string $message,
        public readonly ?int $retryAfter = null,
        array $body = [],
    ) {
        parent::__construct($message, 429, $body);
    }
}
