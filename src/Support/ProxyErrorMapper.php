<?php

declare(strict_types=1);

namespace MyOtpWay\Laravel\Support;

use MyOtpWay\Laravel\Data\VerifyResult;
use MyOtpWay\Laravel\Enums\VerifyFailure;
use MyOtpWay\Laravel\Exceptions\InvalidRequestException;
use MyOtpWay\Laravel\Exceptions\MyOtpWayException;
use MyOtpWay\Laravel\Exceptions\RateLimitException;

/**
 * Turns an internal failure into something safe to hand a phone.
 *
 * The default arm is the point of the class: anything not explicitly judged
 * safe becomes a blank 503. Forgetting a case therefore costs a slightly worse
 * error message, never a leaked balance.
 */
final class ProxyErrorMapper
{
    /** @return array{0: array<string, mixed>, 1: int} */
    public static function forSend(MyOtpWayException $exception): array
    {
        return match (true) {
            $exception instanceof RateLimitException => [
                ['error' => 'rate_limited', 'retry_after' => $exception->retryAfter],
                429,
            ],

            // The recipient the caller supplied really was malformed; telling
            // them so reveals nothing they did not already send us.
            $exception instanceof InvalidRequestException => [
                ['error' => 'invalid_phone'],
                422,
            ],

            default => [['error' => 'service_unavailable'], 503],
        };
    }

    /** @return array{0: array<string, mixed>, 1: int} */
    public static function forVerify(VerifyResult $result): array
    {
        if ($result->verified) {
            return [['verified' => true], 200];
        }

        $body = [
            'verified' => false,
            'error'    => $result->failure?->value ?? 'invalid_code',
        ];

        if ($result->attemptsRemaining !== null) {
            $body['attempts_remaining'] = $result->attemptsRemaining;
        }

        // A locked OTP is a rate-limit condition, and 429 is what tells a client
        // to stop retrying rather than to re-prompt the user.
        $status = $result->failure === VerifyFailure::TooManyAttempts ? 429 : 422;

        return [$body, $status];
    }
}
