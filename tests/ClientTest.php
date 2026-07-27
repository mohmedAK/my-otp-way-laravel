<?php

declare(strict_types=1);

namespace MyOtpWay\Laravel\Tests;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use MyOtpWay\Laravel\Client;
use MyOtpWay\Laravel\Events\MyOtpWayRequestSent;
use MyOtpWay\Laravel\Exceptions\ConnectionFailedException;
use MyOtpWay\Laravel\Exceptions\InsufficientBalanceException;
use MyOtpWay\Laravel\Exceptions\InvalidApiKeyException;

class ClientTest extends TestCase
{
    private function client(): Client
    {
        return new Client('https://api.test/v1', 'test-key', 10);
    }

    public function test_it_sends_the_api_key_header(): void
    {
        Http::fake(['api.test/*' => Http::response(['request_id' => 'abc', 'expires_in' => 300], 202)]);

        $this->client()->post('otp/send', ['to' => '+9647701234567']);

        Http::assertSent(fn ($request) => $request->hasHeader('X-API-Key', 'test-key')
            && $request->url() === 'https://api.test/v1/otp/send');
    }

    public function test_it_returns_the_status_and_body(): void
    {
        Http::fake(['api.test/*' => Http::response(['request_id' => 'abc'], 202)]);

        $response = $this->client()->post('otp/send', []);

        $this->assertSame(202, $response->status);
        $this->assertSame(['request_id' => 'abc'], $response->body);
    }

    public function test_it_throws_a_typed_exception_for_a_failure(): void
    {
        Http::fake(['api.test/*' => Http::response([
            'message' => 'Insufficient API key balance.', 'balance' => 1.5, 'required' => 0.02,
        ], 402)]);

        $this->expectException(InsufficientBalanceException::class);

        $this->client()->post('otp/send', []);
    }

    public function test_an_allowed_status_is_returned_rather_than_thrown(): void
    {
        Http::fake(['api.test/*' => Http::response(['verified' => false, 'reason' => 'Invalid OTP code.'], 400)]);

        $response = $this->client()->post('otp/verify', [], allow: [400]);

        $this->assertSame(400, $response->status);
        $this->assertFalse($response->body['verified']);
    }

    /**
     * A POST that times out may already have been delivered and charged.
     * Retrying it bills the developer twice for one OTP.
     *
     * The fake throws the underlying Guzzle-level ConnectException (rather
     * than Illuminate's own wrapper) because Http::fake only records a
     * request/response pair when the failure is marshalled through Laravel's
     * normal exception-handling path; a directly-thrown
     * Illuminate\Http\Client\ConnectionException bypasses that recording
     * entirely, which would make assertSentCount() report 0 no matter how
     * many attempts actually happened.
     */
    public function test_a_post_is_never_retried(): void
    {
        Http::fake(['api.test/*' => fn () => throw new \GuzzleHttp\Exception\ConnectException(
            'timed out',
            new \GuzzleHttp\Psr7\Request('POST', 'https://api.test/v1/otp/send'),
        )]);

        try {
            $this->client()->post('otp/send', []);
            $this->fail('Expected a ConnectionFailedException.');
        } catch (ConnectionFailedException) {
            // expected
        }

        Http::assertSentCount(1);
    }

    public function test_a_get_retries_on_connection_failure(): void
    {
        Http::fake(['api.test/*' => fn () => throw new \GuzzleHttp\Exception\ConnectException(
            'timed out',
            new \GuzzleHttp\Psr7\Request('GET', 'https://api.test/v1/balance'),
        )]);

        try {
            $this->client()->get('balance');
        } catch (ConnectionFailedException) {
            // expected
        }

        Http::assertSentCount(3); // one attempt plus two retries
    }

    /**
     * A GET retries on connection failure only, never on an HTTP response.
     * Without the `when` clause a PendingRequest treats every non-successful
     * response as retry-worthy, so a revoked key would cost three round trips
     * to reach the same 401 — and an `allow`ed status would spend its retries
     * before the caller ever saw it.
     */
    public function test_a_get_is_not_retried_on_an_http_error_status(): void
    {
        Http::fake(['api.test/*' => Http::response(['message' => 'Unauthenticated.'], 401)]);

        try {
            $this->client()->get('balance');
            $this->fail('Expected an InvalidApiKeyException.');
        } catch (InvalidApiKeyException) {
            // expected
        }

        Http::assertSentCount(1);
    }

    public function test_an_allowed_status_on_a_get_burns_no_retries(): void
    {
        Http::fake(['api.test/*' => Http::response(['status' => 'not_found'], 404)]);

        $response = $this->client()->get('otp/req-1/status', allow: [404]);

        $this->assertSame(404, $response->status);
        Http::assertSentCount(1);
    }

    public function test_it_fires_an_event_for_every_call(): void
    {
        Event::fake([MyOtpWayRequestSent::class]);
        Http::fake(['api.test/*' => Http::response(['request_id' => 'req-1'], 202)]);

        $this->client()->post('otp/send', ['to' => '+9647701234567']);

        Event::assertDispatched(MyOtpWayRequestSent::class, function ($e) {
            return $e->endpoint === 'otp/send'
                && $e->httpStatus === 202
                && $e->requestId === 'req-1'
                && $e->durationMs >= 0;
        });
    }

    /**
     * The event lands in the host application's own logs and metrics. Putting a
     * recipient number or an OTP code there is not our decision to make.
     */
    public function test_the_event_carries_no_recipient_and_no_code(): void
    {
        Event::fake([MyOtpWayRequestSent::class]);
        Http::fake(['api.test/*' => Http::response(['request_id' => 'req-1'], 202)]);

        $this->client()->post('otp/send', ['to' => '+9647701234567', 'code' => '123456']);

        Event::assertDispatched(MyOtpWayRequestSent::class, function ($e) {
            $serialised = json_encode(get_object_vars($e));

            return ! str_contains($serialised, '+9647701234567') && ! str_contains($serialised, '123456');
        });
    }
}
