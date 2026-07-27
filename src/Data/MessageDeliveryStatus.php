<?php

declare(strict_types=1);

namespace MyOtpWay\Laravel\Data;

use Carbon\CarbonImmutable;

final class MessageDeliveryStatus
{
    public function __construct(
        public readonly string $messageId,
        public readonly string $status,
        public readonly ?string $template,
        public readonly ?string $recipient,
        public readonly ?string $errorCode,
        public readonly ?string $errorMessage,
        public readonly ?CarbonImmutable $sentAt,
        public readonly ?CarbonImmutable $createdAt,
    ) {
    }

    public static function fromArray(array $body): self
    {
        return new self(
            messageId: (string) $body['message_id'],
            status: (string) $body['status'],
            template: $body['template'] ?? null,
            recipient: $body['recipient'] ?? null,
            errorCode: $body['error_code'] ?? null,
            errorMessage: $body['error_message'] ?? null,
            sentAt: isset($body['sent_at']) ? CarbonImmutable::parse($body['sent_at']) : null,
            createdAt: isset($body['created_at']) ? CarbonImmutable::parse($body['created_at']) : null,
        );
    }
}
