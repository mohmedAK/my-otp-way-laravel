<?php

declare(strict_types=1);

namespace MyOtpWay\Laravel\Exceptions;

class InsufficientBalanceException extends MyOtpWayException
{
    public function __construct(
        string $message,
        public readonly float $balance,
        public readonly float $required,
        array $body = [],
    ) {
        parent::__construct($message, 402, $body);
    }
}
