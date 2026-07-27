<?php

declare(strict_types=1);

namespace MyOtpWay\Laravel\Tests;

use Illuminate\Support\Facades\Http;
use MyOtpWay\Laravel\Enums\Language;
use MyOtpWay\Laravel\Enums\VerifyFailure;
use MyOtpWay\Laravel\Facades\MyOtpWay;

class OtpResourceTest extends TestCase
{
    public function test_send_posts_the_expected_payload_and_returns_a_result(): void
    {
        Http::fake(['api.test/*' => Http::response(['request_id' => 'req-1', 'expires_in' => 300], 202)]);

        $result = MyOtpWay::otp()->send(
            to: '+9647701234567',
            template: 'verify_code',
            language: Language::Ar,
        );

        $this->assertSame('req-1', $result->requestId);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.test/v1/otp/send'
                && $request['to'] === '+9647701234567'
                && $request['template'] === 'verify_code'
                && $request['language'] === 'ar'
                && $request['channel'] === 'whatsapp';
        });
    }

    public function test_send_accepts_plain_strings_for_the_enums(): void
    {
        Http::fake(['api.test/*' => Http::response(['request_id' => 'req-1', 'expires_in' => 300], 202)]);

        MyOtpWay::otp()->send(to: '+9647701234567', template: 'verify_code', language: 'ku', channel: 'sms');

        Http::assertSent(fn ($request) => $request['language'] === 'ku' && $request['channel'] === 'sms');
    }

    public function test_send_omits_optional_fields_that_were_not_given(): void
    {
        Http::fake(['api.test/*' => Http::response(['request_id' => 'req-1', 'expires_in' => 300], 202)]);

        MyOtpWay::otp()->send(to: '+9647701234567', template: 'verify_code');

        Http::assertSent(fn ($request) => ! array_key_exists('length', $request->data())
            && ! array_key_exists('code', $request->data()));
    }

    public function test_verify_returns_a_result_rather_than_throwing_on_a_wrong_code(): void
    {
        Http::fake(['api.test/*' => Http::response([
            'verified' => false, 'reason' => 'Invalid OTP code.', 'attempts_remaining' => 4,
        ], 400)]);

        $result = MyOtpWay::otp()->verify('req-1', '000000');

        $this->assertFalse($result->verified);
        $this->assertSame(VerifyFailure::InvalidCode, $result->failure);
        $this->assertSame(4, $result->attemptsRemaining);
    }

    public function test_verify_returns_true_on_success(): void
    {
        Http::fake(['api.test/*' => Http::response(['verified' => true], 200)]);

        $this->assertTrue(MyOtpWay::otp()->verify('req-1', '123456')->verified);
    }

    public function test_status_returns_a_delivery_status(): void
    {
        Http::fake(['api.test/*' => Http::response([
            'request_id' => 'req-1', 'status' => 'delivered', 'channel' => 'whatsapp',
            'recipient' => '+9647701234567', 'error_code' => null, 'error_message' => null,
            'sent_at' => '2026-07-27T12:00:00+00:00', 'delivered_at' => '2026-07-27T12:00:04+00:00',
            'expires_at' => '2026-07-27T12:05:00+00:00',
        ], 200)]);

        $status = MyOtpWay::otp()->status('req-1');

        $this->assertSame('delivered', $status->status);

        Http::assertSent(fn ($request) => $request->url() === 'https://api.test/v1/otp/req-1/status');
    }
}
