<?php

declare(strict_types=1);

namespace MyOtpWay\Laravel;

use MyOtpWay\Laravel\Data\Balance;
use MyOtpWay\Laravel\Resources\MessageResource;
use MyOtpWay\Laravel\Resources\OtpResource;

class MyOtpWayManager
{
    private ?Client $client = null;

    public function __construct(private readonly array $config)
    {
    }

    public function config(?string $key = null): mixed
    {
        return $key === null ? $this->config : data_get($this->config, $key);
    }

    public function client(): Client
    {
        return $this->client ??= new Client(
            baseUrl: (string) $this->config['base_url'],
            apiKey: $this->config['api_key'] ?? null,
            timeout: (int) ($this->config['timeout'] ?? 10),
        );
    }

    public function otp(): OtpResource
    {
        return new OtpResource($this->client());
    }

    public function messages(): MessageResource
    {
        return new MessageResource($this->client());
    }

    public function balance(): Balance
    {
        return Balance::fromArray($this->client()->get('balance')->body);
    }
}
