<?php

declare(strict_types=1);

namespace MyOtpWay\Laravel\Resources;

use MyOtpWay\Laravel\Client;
use MyOtpWay\Laravel\Data\OtpDeliveryStatus;
use MyOtpWay\Laravel\Data\SendResult;
use MyOtpWay\Laravel\Data\VerifyResult;
use MyOtpWay\Laravel\Enums\Channel;
use MyOtpWay\Laravel\Enums\Language;

final class OtpResource
{
    public function __construct(private readonly Client $client)
    {
    }

    public function send(
        string $to,
        ?string $template = null,
        Language|string $language = Language::En,
        Channel|string $channel = Channel::Whatsapp,
        ?int $length = null,
        ?string $code = null,
    ): SendResult {
        $payload = array_filter([
            'to'       => $to,
            'template' => $template,
            'language' => $language instanceof Language ? $language->value : $language,
            'channel'  => $channel instanceof Channel ? $channel->value : $channel,
            'length'   => $length,
            'code'     => $code,
        ], static fn ($value) => $value !== null);

        return SendResult::fromArray($this->client->post('otp/send', $payload)->body);
    }

    /**
     * A wrong or expired code is an outcome the caller must branch on, not an
     * exception, so those statuses are allowed through the client untouched.
     */
    public function verify(string $requestId, string $code): VerifyResult
    {
        $response = $this->client->post(
            'otp/verify',
            ['request_id' => $requestId, 'code' => $code],
            allow: [400, 404, 429],
        );

        return VerifyResult::fromResponse($response->status, $response->body);
    }

    public function status(string $requestId): OtpDeliveryStatus
    {
        return OtpDeliveryStatus::fromArray($this->client->get("otp/{$requestId}/status")->body);
    }
}
