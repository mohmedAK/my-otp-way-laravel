<?php

declare(strict_types=1);

namespace MyOtpWay\Laravel\Tests;

use Illuminate\Support\Facades\Http;
use MyOtpWay\Laravel\Exceptions\InsufficientBalanceException;
use MyOtpWay\Laravel\Facades\MyOtpWay;
use PHPUnit\Framework\AssertionFailedError;

class FakeTest extends TestCase
{
    public function test_the_fake_makes_no_http_call(): void
    {
        Http::fake();
        MyOtpWay::fake();

        MyOtpWay::otp()->send(to: '+9647701234567', template: 'verify_code');

        Http::assertNothingSent();
    }

    public function test_it_asserts_a_send_happened(): void
    {
        $fake = MyOtpWay::fake();

        MyOtpWay::otp()->send(to: '+9647701234567', template: 'verify_code');

        $fake->assertSent('+9647701234567');
    }

    public function test_it_fails_the_assertion_when_nothing_was_sent(): void
    {
        $fake = MyOtpWay::fake();

        $this->expectException(AssertionFailedError::class);

        $fake->assertSent('+9647701234567');
    }

    /**
     * A host application's feature test needs to finish the round trip, which
     * means knowing the code the fake claims to have delivered.
     */
    public function test_the_code_it_pretended_to_send_verifies(): void
    {
        $fake = MyOtpWay::fake();

        $result = MyOtpWay::otp()->send(to: '+9647701234567', template: 'verify_code');
        $code   = $fake->codeFor('+9647701234567');

        $this->assertNotNull($code);
        $this->assertTrue(MyOtpWay::otp()->verify($result->requestId, $code)->verified);
    }

    public function test_a_wrong_code_does_not_verify(): void
    {
        MyOtpWay::fake();

        $result = MyOtpWay::otp()->send(to: '+9647701234567', template: 'verify_code');

        $this->assertFalse(MyOtpWay::otp()->verify($result->requestId, '000000')->verified);
    }

    public function test_it_can_be_told_to_fail(): void
    {
        $fake = MyOtpWay::fake();
        $fake->shouldFailWith(new InsufficientBalanceException('Insufficient API key balance.', 0.0, 0.02));

        $this->expectException(InsufficientBalanceException::class);

        MyOtpWay::otp()->send(to: '+9647701234567', template: 'verify_code');
    }
}
