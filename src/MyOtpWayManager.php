<?php

declare(strict_types=1);

namespace MyOtpWay\Laravel;

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
}
