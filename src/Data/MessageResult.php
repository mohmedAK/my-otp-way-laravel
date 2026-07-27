<?php

declare(strict_types=1);

namespace MyOtpWay\Laravel\Data;

final class MessageResult
{
    public function __construct(
        public readonly string $messageId,
        public readonly string $template,
        public readonly string $language,
        public readonly string $status,
    ) {
    }

    public static function fromArray(array $body): self
    {
        $data = $body['data'] ?? [];

        return new self(
            messageId: (string) $data['message_id'],
            template: (string) $data['template'],
            language: (string) $data['language'],
            status: (string) $data['status'],
        );
    }
}
