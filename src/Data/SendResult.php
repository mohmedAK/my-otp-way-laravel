<?php

declare(strict_types=1);

namespace MyOtpWay\Laravel\Data;

use Carbon\CarbonImmutable;

final class SendResult
{
    public function __construct(
        public readonly string $requestId,
        public readonly CarbonImmutable $expiresAt,
    ) {
    }

    public static function fromArray(array $body): self
    {
        return new self(
            requestId: (string) $body['request_id'],
            // The API sends a relative "expires_in"; resolve it once, here, so
            // callers never have to remember when they received the response.
            expiresAt: CarbonImmutable::now()->addSeconds((int) ($body['expires_in'] ?? 0)),
        );
    }
}
