<?php

declare(strict_types=1);

namespace MyOtpWay\Laravel\Exceptions;

final class ExceptionFactory
{
    /**
     * Deliberately primitive in its arguments: the contract test in the host
     * application replays genuine upstream bodies as plain arrays.
     */
    public static function fromStatusAndBody(int $status, array $body): MyOtpWayException
    {
        $message = (string) ($body['message'] ?? 'MY-OTP-Way request failed.');

        return match (true) {
            $status === 401 => new InvalidApiKeyException($message, 401, $body),

            $status === 402 => new InsufficientBalanceException(
                $message,
                (float) ($body['balance'] ?? 0.0),
                (float) ($body['required'] ?? 0.0),
                $body,
            ),

            // The upstream emits no machine code for its three 403s; prose is
            // all we have. Unrecognised wording falls through to suspended,
            // which sanitises identically.
            $status === 403 && str_contains($message, 'IP address')  => new IpNotAllowedException($message, 403, $body),
            $status === 403 && str_contains($message, 'SMS is disabled') => new SmsDisabledException($message, 403, $body),
            $status === 403 => new AccountSuspendedException($message, 403, $body),

            $status === 404 => new TemplateNotFoundException($message, 404, $body),

            $status === 422 => new InvalidRequestException($message, (array) ($body['errors'] ?? []), $body),

            $status === 429 => new RateLimitException(
                $message,
                isset($body['retry_after']) ? (int) $body['retry_after'] : null,
                $body,
            ),

            $status === 503 => new NoSenderAvailableException($message, 503, $body),

            default => new MyOtpWayException($message, $status, $body),
        };
    }
}
