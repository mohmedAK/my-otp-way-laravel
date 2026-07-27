<?php

declare(strict_types=1);

namespace MyOtpWay\Laravel\Data;

use Carbon\CarbonImmutable;

final class OtpDeliveryStatus
{
    public function __construct(
        public readonly string $requestId,
        public readonly string $status,
        public readonly ?string $channel,
        public readonly ?string $recipient,
        public readonly ?string $errorCode,
        public readonly ?string $errorMessage,
        public readonly ?CarbonImmutable $sentAt,
        public readonly ?CarbonImmutable $deliveredAt,
        public readonly ?CarbonImmutable $expiresAt,
    ) {
    }

    public static function fromArray(array $body): self
    {
        return new self(
            requestId: (string) $body['request_id'],
            status: (string) $body['status'],
            channel: $body['channel'] ?? null,
            recipient: $body['recipient'] ?? null,
            errorCode: $body['error_code'] ?? null,
            errorMessage: $body['error_message'] ?? null,
            sentAt: self::instant($body['sent_at'] ?? null),
            deliveredAt: self::instant($body['delivered_at'] ?? null),
            expiresAt: self::instant($body['expires_at'] ?? null),
        );
    }

    private static function instant(?string $value): ?CarbonImmutable
    {
        return $value === null ? null : CarbonImmutable::parse($value);
    }
}
