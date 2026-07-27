<?php

declare(strict_types=1);

namespace MyOtpWay\Laravel\Tests;

use Illuminate\Support\Carbon;
use MyOtpWay\Laravel\Data\Balance;
use MyOtpWay\Laravel\Data\OtpDeliveryStatus;
use MyOtpWay\Laravel\Data\SendResult;
use MyOtpWay\Laravel\Data\VerifyResult;
use MyOtpWay\Laravel\Enums\VerifyFailure;

class DataTest extends TestCase
{
    /**
     * The API returns a relative "expires_in: 300". A caller that stores the
     * result and reads it a minute later needs an absolute instant, so the DTO
     * resolves it once, at parse time.
     */
    public function test_send_result_converts_expires_in_to_an_absolute_instant(): void
    {
        Carbon::setTestNow('2026-07-27 12:00:00');

        $result = SendResult::fromArray(['request_id' => 'abc-123', 'expires_in' => 300]);

        $this->assertSame('abc-123', $result->requestId);
        $this->assertSame('2026-07-27 12:05:00', $result->expiresAt->format('Y-m-d H:i:s'));
    }

    public function test_verify_result_reads_a_success(): void
    {
        $result = VerifyResult::fromResponse(200, ['verified' => true]);

        $this->assertTrue($result->verified);
        $this->assertNull($result->failure);
    }

    public function test_verify_result_classifies_each_upstream_reason(): void
    {
        $cases = [
            [400, ['verified' => false, 'reason' => 'Invalid OTP code.', 'attempts_remaining' => 3], VerifyFailure::InvalidCode],
            [400, ['verified' => false, 'reason' => 'OTP has expired.'], VerifyFailure::Expired],
            [400, ['verified' => false, 'reason' => 'OTP already used.'], VerifyFailure::AlreadyUsed],
            [429, ['verified' => false, 'reason' => 'Too many attempts. OTP locked.'], VerifyFailure::TooManyAttempts],
            [404, ['verified' => false, 'reason' => 'Invalid request ID.'], VerifyFailure::NotFound],
        ];

        foreach ($cases as [$status, $body, $expected]) {
            $result = VerifyResult::fromResponse($status, $body);

            $this->assertFalse($result->verified, $body['reason']);
            $this->assertSame($expected, $result->failure, $body['reason']);
        }
    }

    public function test_verify_result_reads_attempts_remaining(): void
    {
        $result = VerifyResult::fromResponse(400, [
            'verified' => false, 'reason' => 'Invalid OTP code.', 'attempts_remaining' => 3,
        ]);

        $this->assertSame(3, $result->attemptsRemaining);
    }

    public function test_balance_reads_both_currencies(): void
    {
        $balance = Balance::fromArray([
            'balance' => ['usd' => 32.7977, 'iqd' => 42965, 'exchange_rate' => 1310],
        ]);

        $this->assertSame(32.7977, $balance->usd);
        $this->assertSame(42965, $balance->iqd);
        $this->assertSame(1310.0, $balance->exchangeRate);
    }

    public function test_otp_delivery_status_parses_nullable_timestamps(): void
    {
        $status = OtpDeliveryStatus::fromArray([
            'request_id'    => 'abc-123',
            'status'        => 'sent',
            'channel'       => 'whatsapp',
            'recipient'     => '+9647701234567',
            'error_code'    => null,
            'error_message' => null,
            'sent_at'       => '2026-07-27T12:00:00+00:00',
            'delivered_at'  => null,
            'expires_at'    => '2026-07-27T12:05:00+00:00',
        ]);

        $this->assertSame('sent', $status->status);
        $this->assertSame('2026-07-27 12:00:00', $status->sentAt?->format('Y-m-d H:i:s'));
        $this->assertNull($status->deliveredAt);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }
}
