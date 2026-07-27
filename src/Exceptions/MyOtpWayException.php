<?php

declare(strict_types=1);

namespace MyOtpWay\Laravel\Exceptions;

use RuntimeException;

class MyOtpWayException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $httpStatus = 0,
        public readonly array $body = [],
    ) {
        parent::__construct($message);
    }
}
