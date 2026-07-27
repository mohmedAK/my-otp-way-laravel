<?php

declare(strict_types=1);

namespace MyOtpWay\Laravel\Tests;

use MyOtpWay\Laravel\Data\VerifyResult;
use MyOtpWay\Laravel\Enums\VerifyFailure;
use MyOtpWay\Laravel\Exceptions\AccountSuspendedException;
use MyOtpWay\Laravel\Exceptions\ConnectionFailedException;
use MyOtpWay\Laravel\Exceptions\InsufficientBalanceException;
use MyOtpWay\Laravel\Exceptions\InvalidApiKeyException;
use MyOtpWay\Laravel\Exceptions\InvalidRequestException;
use MyOtpWay\Laravel\Exceptions\IpNotAllowedException;
use MyOtpWay\Laravel\Exceptions\MyOtpWayException;
use MyOtpWay\Laravel\Exceptions\NoSenderAvailableException;
use MyOtpWay\Laravel\Exceptions\RateLimitException;
use MyOtpWay\Laravel\Exceptions\SmsDisabledException;
use MyOtpWay\Laravel\Exceptions\TemplateNotFoundException;
use MyOtpWay\Laravel\Support\ProxyErrorMapper;
use PHPUnit\Framework\TestCase as BaseTestCase;

class ProxyErrorMapperTest extends BaseTestCase
{
    /** @return list<MyOtpWayException> every failure the client can raise */
    private function everyException(): array
    {
        return [
            new InvalidApiKeyException('Invalid or revoked API key.', 401),
            new InsufficientBalanceException('Insufficient API key balance.', 12.4, 0.02, ['balance' => 12.4]),
            new AccountSuspendedException('Account suspended.', 403),
            new IpNotAllowedException('IP address not allowed.', 403),
            new SmsDisabledException('SMS is disabled for this API key.', 403),
            new TemplateNotFoundException('Template not found or not approved.', 404),
            new InvalidRequestException('Validation failed.', ['to' => ['bad']]),
            new RateLimitException('Rate limit exceeded.', 900),
            new NoSenderAvailableException('No active sending number available.', 503),
            new ConnectionFailedException('cURL error 28'),
            new MyOtpWayException('Something new we have never seen.', 418),
        ];
    }

    /**
     * The single most important assertion in this package. Whatever the
     * upstream said, the phone must never learn the developer's balance, the
     * state of their API key, or the wording of an internal error.
     */
    public function test_no_failure_ever_leaks_money_or_internals(): void
    {
        foreach ($this->everyException() as $exception) {
            [$body, $status] = ProxyErrorMapper::forSend($exception);

            $serialised = strtolower(json_encode($body));

            $this->assertStringNotContainsString('balance', $serialised, $exception::class);
            $this->assertStringNotContainsString('12.4', $serialised, $exception::class);
            $this->assertStringNotContainsString('api key', $serialised, $exception::class);
            $this->assertStringNotContainsString('ip address', $serialised, $exception::class);
            $this->assertStringNotContainsString('template', $serialised, $exception::class);
            $this->assertContains($status, [422, 429, 503], $exception::class);
        }
    }

    /**
     * A failure class nobody enumerated must land on the safe answer, not on a
     * passthrough. This is what makes forgetting a case harmless.
     */
    public function test_an_unrecognised_failure_degrades_to_service_unavailable(): void
    {
        [$body, $status] = ProxyErrorMapper::forSend(new MyOtpWayException('brand new failure', 418));

        $this->assertSame(['error' => 'service_unavailable'], $body);
        $this->assertSame(503, $status);
    }

    public function test_a_rate_limit_passes_through_with_its_retry_after(): void
    {
        [$body, $status] = ProxyErrorMapper::forSend(new RateLimitException('too fast', 900));

        $this->assertSame(['error' => 'rate_limited', 'retry_after' => 900], $body);
        $this->assertSame(429, $status);
    }

    public function test_a_bad_recipient_passes_through_as_invalid_phone(): void
    {
        [$body, $status] = ProxyErrorMapper::forSend(new InvalidRequestException('Validation failed.', ['to' => ['bad']]));

        $this->assertSame(['error' => 'invalid_phone'], $body);
        $this->assertSame(422, $status);
    }

    public function test_the_balance_failure_becomes_a_blank_503(): void
    {
        [$body, $status] = ProxyErrorMapper::forSend(
            new InsufficientBalanceException('Insufficient API key balance.', 12.4, 0.02)
        );

        $this->assertSame(['error' => 'service_unavailable'], $body);
        $this->assertSame(503, $status);
    }

    public function test_verify_success_maps_to_a_200(): void
    {
        [$body, $status] = ProxyErrorMapper::forVerify(new VerifyResult(verified: true));

        $this->assertSame(['verified' => true], $body);
        $this->assertSame(200, $status);
    }

    public function test_every_verify_failure_maps_to_a_stable_machine_code(): void
    {
        $expected = [
            [VerifyFailure::InvalidCode,     'invalid_code',      422],
            [VerifyFailure::Expired,         'expired',           422],
            [VerifyFailure::AlreadyUsed,     'already_used',      422],
            [VerifyFailure::NotFound,        'not_found',         422],
            [VerifyFailure::TooManyAttempts, 'too_many_attempts', 429],
        ];

        foreach ($expected as [$failure, $code, $httpStatus]) {
            [$body, $status] = ProxyErrorMapper::forVerify(new VerifyResult(verified: false, failure: $failure));

            $this->assertSame($code, $body['error'], $failure->value);
            $this->assertFalse($body['verified'], $failure->value);
            $this->assertSame($httpStatus, $status, $failure->value);
        }
    }

    public function test_a_wrong_code_reports_the_attempts_left(): void
    {
        [$body] = ProxyErrorMapper::forVerify(
            new VerifyResult(verified: false, failure: VerifyFailure::InvalidCode, attemptsRemaining: 3)
        );

        $this->assertSame(3, $body['attempts_remaining']);
    }
}
