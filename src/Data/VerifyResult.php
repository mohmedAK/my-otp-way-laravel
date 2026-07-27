<?php

declare(strict_types=1);

namespace MyOtpWay\Laravel\Data;

use MyOtpWay\Laravel\Enums\VerifyFailure;

final class VerifyResult
{
    public function __construct(
        public readonly bool $verified,
        public readonly ?VerifyFailure $failure = null,
        public readonly ?int $attemptsRemaining = null,
    ) {
    }

    /**
     * A wrong code is an outcome, not an error, so it arrives here rather than
     * as an exception. The upstream signals the reason in English prose only,
     * so classification matches on substrings and falls back to InvalidCode.
     */
    public static function fromResponse(int $status, array $body): self
    {
        if (($body['verified'] ?? false) === true) {
            return new self(verified: true);
        }

        $reason = (string) ($body['reason'] ?? '');

        $failure = match (true) {
            $status === 404 || str_contains($reason, 'Invalid request ID') => VerifyFailure::NotFound,
            $status === 429 || str_contains($reason, 'Too many attempts')  => VerifyFailure::TooManyAttempts,
            str_contains($reason, 'expired')      => VerifyFailure::Expired,
            str_contains($reason, 'already used') => VerifyFailure::AlreadyUsed,
            default                               => VerifyFailure::InvalidCode,
        };

        return new self(
            verified: false,
            failure: $failure,
            attemptsRemaining: isset($body['attempts_remaining']) ? (int) $body['attempts_remaining'] : null,
        );
    }
}
