<?php

declare(strict_types=1);

namespace MyOtpWay\Laravel\Data;

final class Balance
{
    public function __construct(
        public readonly float $usd,
        public readonly int $iqd,
        public readonly float $exchangeRate,
    ) {
    }

    public static function fromArray(array $body): self
    {
        $balance = $body['balance'] ?? [];

        return new self(
            usd: (float) ($balance['usd'] ?? 0),
            iqd: (int) ($balance['iqd'] ?? 0),
            exchangeRate: (float) ($balance['exchange_rate'] ?? 0),
        );
    }
}
