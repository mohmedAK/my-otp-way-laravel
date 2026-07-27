<?php

declare(strict_types=1);

namespace MyOtpWay\Laravel\Support;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Carbon;

/**
 * Stops resend spam before it reaches the platform and costs anything.
 *
 * Two records per phone: a short cooldown that gates the resend button, and a
 * longer marker proving a send actually happened, so /resend cannot be used as
 * a second /send route with a laxer throttle.
 */
final class ResendCooldown
{
    public function __construct(
        private readonly Repository $cache,
        private readonly int $seconds,
    ) {
    }

    public function start(string $phone, int $otpTtlSeconds): void
    {
        $this->cache->put($this->cooldownKey($phone), Carbon::now()->addSeconds($this->seconds)->getTimestamp(), $this->seconds);
        $this->cache->put($this->pendingKey($phone), true, max($otpTtlSeconds, 1));
    }

    public function remaining(string $phone): int
    {
        $expiresAt = $this->cache->get($this->cooldownKey($phone));

        if ($expiresAt === null) {
            return 0;
        }

        return max(0, (int) $expiresAt - Carbon::now()->getTimestamp());
    }

    public function hasPendingSend(string $phone): bool
    {
        return (bool) $this->cache->get($this->pendingKey($phone), false);
    }

    // Hashed: cache keyspaces are readable by anyone with monitoring access,
    // and a raw phone number there is a customer list.
    private function cooldownKey(string $phone): string
    {
        return 'my-otp-way:cooldown:' . sha1($phone);
    }

    private function pendingKey(string $phone): string
    {
        return 'my-otp-way:pending:' . sha1($phone);
    }
}
