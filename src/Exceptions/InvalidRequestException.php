<?php

declare(strict_types=1);

namespace MyOtpWay\Laravel\Exceptions;

/**
 * Named InvalidRequestException rather than ValidationException so a host
 * application importing both this and Illuminate\Validation\ValidationException
 * never has to disambiguate the two.
 */
class InvalidRequestException extends MyOtpWayException
{
    public function __construct(
        string $message,
        public readonly array $errors = [],
        array $body = [],
    ) {
        parent::__construct($message, 422, $body);
    }
}
