<?php

declare(strict_types=1);

namespace MyOtpWay\Laravel\Testing;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use MyOtpWay\Laravel\Data\SendResult;
use MyOtpWay\Laravel\Data\VerifyResult;
use MyOtpWay\Laravel\Enums\Channel;
use MyOtpWay\Laravel\Enums\Language;
use MyOtpWay\Laravel\Enums\VerifyFailure;
use MyOtpWay\Laravel\Exceptions\MyOtpWayException;
use PHPUnit\Framework\Assert;

/**
 * Stands in for OtpResource in a host application's tests. Deliberately
 * duck-typed rather than extending OtpResource: the real class needs a Client,
 * and a fake that has to construct one is a fake that can still reach the
 * network by accident.
 */
final class MyOtpWayFake
{
    /** @var list<array{to: string, template: ?string, request_id: string, code: string}> */
    private array $sent = [];

    private ?MyOtpWayException $failure = null;

    public function shouldFailWith(MyOtpWayException $exception): void
    {
        $this->failure = $exception;
    }

    public function send(
        string $to,
        ?string $template = null,
        Language|string $language = Language::En,
        Channel|string $channel = Channel::Whatsapp,
        ?int $length = null,
        ?string $code = null,
    ): SendResult {
        if ($this->failure !== null) {
            throw $this->failure;
        }

        $requestId = (string) Str::uuid();
        $sentCode  = $code ?? str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $this->sent[] = ['to' => $to, 'template' => $template, 'request_id' => $requestId, 'code' => $sentCode];

        return new SendResult($requestId, CarbonImmutable::now()->addMinutes(5));
    }

    public function verify(string $requestId, string $code): VerifyResult
    {
        foreach ($this->sent as $record) {
            if ($record['request_id'] === $requestId) {
                return $record['code'] === $code
                    ? new VerifyResult(verified: true)
                    : new VerifyResult(verified: false, failure: VerifyFailure::InvalidCode, attemptsRemaining: 4);
            }
        }

        return new VerifyResult(verified: false, failure: VerifyFailure::NotFound);
    }

    public function assertSent(string $to): void
    {
        Assert::assertTrue(
            collect($this->sent)->contains(fn (array $record) => $record['to'] === $to),
            "Expected an OTP to have been sent to {$to}, but none was.",
        );
    }

    public function assertNothingSent(): void
    {
        Assert::assertSame([], $this->sent, 'Expected no OTP to have been sent.');
    }

    public function codeFor(string $to): ?string
    {
        foreach (array_reverse($this->sent) as $record) {
            if ($record['to'] === $to) {
                return $record['code'];
            }
        }

        return null;
    }
}
