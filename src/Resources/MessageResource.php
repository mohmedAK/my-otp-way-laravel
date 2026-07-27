<?php

declare(strict_types=1);

namespace MyOtpWay\Laravel\Resources;

use MyOtpWay\Laravel\Client;
use MyOtpWay\Laravel\Data\MessageDeliveryStatus;
use MyOtpWay\Laravel\Data\MessageResult;
use MyOtpWay\Laravel\Enums\Language;

final class MessageResource
{
    public function __construct(private readonly Client $client)
    {
    }

    /**
     * $variables passes through untouched: a PHP list encodes to a JSON array
     * (positional template) and a keyed array to a JSON object (named
     * template), and the API distinguishes the two.
     */
    public function send(
        string $to,
        string $template,
        array $variables = [],
        Language|string $language = Language::En,
    ): MessageResult {
        $payload = [
            'to'       => $to,
            'template' => $template,
            'language' => $language instanceof Language ? $language->value : $language,
        ];

        if ($variables !== []) {
            $payload['variables'] = $variables;
        }

        return MessageResult::fromArray($this->client->post('message/send', $payload)->body);
    }

    public function status(string $messageId): MessageDeliveryStatus
    {
        return MessageDeliveryStatus::fromArray($this->client->get("message/{$messageId}/status")->body);
    }
}
