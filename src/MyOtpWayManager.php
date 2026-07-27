<?php

declare(strict_types=1);

namespace MyOtpWay\Laravel;

class MyOtpWayManager
{
    public function __construct(private readonly array $config)
    {
    }

    public function config(?string $key = null): mixed
    {
        return $key === null ? $this->config : data_get($this->config, $key);
    }
}
