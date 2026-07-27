<?php

declare(strict_types=1);

namespace MyOtpWay\Laravel;

final class ApiResponse
{
    public function __construct(
        public readonly int $status,
        public readonly array $body,
    ) {
    }
}
