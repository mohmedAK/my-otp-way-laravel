<?php

declare(strict_types=1);

namespace MyOtpWay\Laravel\Tests;

use MyOtpWay\Laravel\Exceptions\AccountSuspendedException;
use MyOtpWay\Laravel\Exceptions\ExceptionFactory;
use MyOtpWay\Laravel\Exceptions\InsufficientBalanceException;
use MyOtpWay\Laravel\Exceptions\InvalidApiKeyException;
use MyOtpWay\Laravel\Exceptions\InvalidRequestException;
use MyOtpWay\Laravel\Exceptions\IpNotAllowedException;
use MyOtpWay\Laravel\Exceptions\NoSenderAvailableException;
use MyOtpWay\Laravel\Exceptions\RateLimitException;
use MyOtpWay\Laravel\Exceptions\SmsDisabledException;
use MyOtpWay\Laravel\Exceptions\TemplateNotFoundException;
use PHPUnit\Framework\TestCase as BaseTestCase;

class ExceptionFactoryTest extends BaseTestCase
{
    public function test_it_maps_401_to_an_invalid_key_exception(): void
    {
        $e = ExceptionFactory::fromStatusAndBody(401, ['message' => 'Invalid or revoked API key.']);

        $this->assertInstanceOf(InvalidApiKeyException::class, $e);
        $this->assertSame(401, $e->httpStatus);
    }

    public function test_it_carries_the_balance_out_of_a_402(): void
    {
        $e = ExceptionFactory::fromStatusAndBody(402, [
            'message'  => 'Insufficient API key balance.',
            'balance'  => 12.4,
            'required' => 0.02,
        ]);

        $this->assertInstanceOf(InsufficientBalanceException::class, $e);
        $this->assertSame(12.4, $e->balance);
        $this->assertSame(0.02, $e->required);
    }

    /**
     * The upstream distinguishes its three 403s by English prose only. If the
     * wording drifts these fall back to AccountSuspendedException, which is
     * safe because all three sanitise identically.
     */
    public function test_it_separates_the_three_forbidden_reasons(): void
    {
        $this->assertInstanceOf(
            IpNotAllowedException::class,
            ExceptionFactory::fromStatusAndBody(403, ['message' => 'IP address not allowed.'])
        );
        $this->assertInstanceOf(
            SmsDisabledException::class,
            ExceptionFactory::fromStatusAndBody(403, ['message' => 'SMS is disabled for this API key.'])
        );
        $this->assertInstanceOf(
            AccountSuspendedException::class,
            ExceptionFactory::fromStatusAndBody(403, ['message' => 'Account suspended.'])
        );
    }

    public function test_it_maps_the_remaining_statuses(): void
    {
        $this->assertInstanceOf(
            TemplateNotFoundException::class,
            ExceptionFactory::fromStatusAndBody(404, ['message' => 'Template not found or not approved.'])
        );
        $this->assertInstanceOf(
            NoSenderAvailableException::class,
            ExceptionFactory::fromStatusAndBody(503, ['message' => 'No active sending number available.'])
        );
    }

    public function test_it_carries_validation_errors_out_of_a_422(): void
    {
        $e = ExceptionFactory::fromStatusAndBody(422, [
            'message' => 'Validation failed.',
            'errors'  => ['to' => ['The to field format is invalid.']],
        ]);

        $this->assertInstanceOf(InvalidRequestException::class, $e);
        $this->assertSame(['to' => ['The to field format is invalid.']], $e->errors);
    }

    public function test_it_carries_retry_after_out_of_a_429(): void
    {
        $e = ExceptionFactory::fromStatusAndBody(429, [
            'message'     => 'Rate limit exceeded: maximum 10 OTPs per 15 minutes for this number.',
            'retry_after' => 900,
        ]);

        $this->assertInstanceOf(RateLimitException::class, $e);
        $this->assertSame(900, $e->retryAfter);
    }

    /**
     * The upstream's daily rate-limit response carries no retry_after at all.
     */
    public function test_retry_after_is_null_when_the_upstream_omits_it(): void
    {
        $e = ExceptionFactory::fromStatusAndBody(429, ['message' => 'Rate limit exceeded: maximum 20 OTPs per day for this number.']);

        $this->assertInstanceOf(RateLimitException::class, $e);
        $this->assertNull($e->retryAfter);
    }

    public function test_an_unrecognised_status_still_produces_a_base_exception(): void
    {
        $e = ExceptionFactory::fromStatusAndBody(418, ['message' => 'I am a teapot.']);

        $this->assertSame(418, $e->httpStatus);
        $this->assertSame('I am a teapot.', $e->getMessage());
    }
}
