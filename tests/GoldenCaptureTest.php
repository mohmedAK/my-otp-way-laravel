<?php

declare(strict_types=1);

namespace MyOtpWay\Laravel\Tests;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use MyOtpWay\Laravel\Facades\MyOtpWay;

/**
 * Records the exact bytes this package puts on the wire.
 *
 * `proxy_contract.json` pins a *schema* — codes, statuses, extra field names.
 * Both suites assert against it and both are satisfied by a body they wrote
 * themselves, so nothing in either package has ever observed the other side's
 * actual output. Number-vs-string typing, key order, unicode escaping, the
 * Content-Type and every key the schema does not mention all live in that gap.
 *
 * This class closes the PHP half: it drives real requests through the real
 * routes and freezes `$response->getContent()` verbatim into
 * `flutter-sdk/test/fixtures/golden_responses.json`, which
 * `flutter-sdk/test/golden_replay_test.dart` then feeds to the real Dart
 * client. Nothing here is hand-written — a hand-written body would only prove
 * that this file agrees with itself.
 *
 * Normal runs COMPARE and fail on drift. Re-record deliberately with:
 *
 *     MY_OTP_WAY_UPDATE_GOLDEN=1 vendor/bin/phpunit --filter=GoldenCaptureTest
 *
 * and read the diff before committing it: every byte that moves here is a byte
 * a published mobile app is already parsing.
 */
class GoldenCaptureTest extends TestCase
{
    use SharedContract;

    /**
     * Frozen so the file is comparable between runs. `expires_at` is
     * `now() + expires_in` and the throttle's `retry_after` counts down a real
     * window, so unfrozen captures would differ on every run and the drift
     * check would be worthless. The bytes are still the framework's.
     */
    private const FROZEN_NOW = '2026-07-29T12:00:00+00:00';

    /** @var list<array{name: string, status: int, content_type: ?string, body: string}> */
    private array $captures = [];

    /** The callable the upstream fake defers to; swapped before each capture. */
    private $upstream;

    protected function defineRoutes($router): void
    {
        MyOtpWay::routes();
    }

    private function goldenPath(): string
    {
        return __DIR__ . '/../../flutter-sdk/test/fixtures/golden_responses.json';
    }

    private function capture(string $name, TestResponse $response): TestResponse
    {
        $this->captures[] = [
            'name'         => $name,
            'status'       => $response->getStatusCode(),
            'content_type' => $response->headers->get('Content-Type'),
            'body'         => (string) $response->getContent(),
        ];

        return $response;
    }

    /** Swaps what the platform answers for the next proxied call. */
    private function upstreamReturns(int $status, array $body): void
    {
        $this->upstream = fn () => Http::response($body, $status);
    }

    public function test_it_records_the_bytes_the_flutter_client_will_parse(): void
    {
        $this->travelTo(Carbon::parse(self::FROZEN_NOW));

        // One registration, dispatched through a mutable callable: Http::fake()
        // accumulates stubs and resolves first-match-wins by registration
        // order, so a second catch-all registered later in this method could
        // never win over this one.
        $this->upstream = fn () => Http::response([], 500);
        Http::fake(['api.test/*' => fn ($request) => ($this->upstream)($request)]);

        $sent = ['request_id' => 'req-1', 'expires_in' => 300];

        // ---- send route, first budget (ceiling 5/min) ----------------------
        $this->upstreamReturns(202, $sent);
        $this->capture('send_success', $this->postJson('/my-otp/send', ['phone' => '+9647701234567']))
            ->assertStatus(202);

        $this->capture('resend_too_soon', $this->postJson('/my-otp/send', ['phone' => '+9647701234567']))
            ->assertStatus(429);

        // The only path by which text the proxy did not author reaches its own
        // body. A real request id is a uuid; an Arabic one is here to make the
        // encoder's unicode handling observable rather than assumed, because
        // package:http decodes a charset-less `application/json` as latin1 and
        // would mangle raw UTF-8 on the way in.
        $this->upstreamReturns(202, ['request_id' => 'طلب-٧', 'expires_in' => 300]);
        $this->capture('send_success_unicode_request_id', $this->postJson('/my-otp/send', ['phone' => '+9647701234568']))
            ->assertStatus(202);

        MyOtpWay::authorizeUsing(fn ($request) => $request->header('X-App-Token') === 'let-me-in');
        $this->capture('forbidden', $this->postJson('/my-otp/send', ['phone' => '+9647701234569']))
            ->assertStatus(403);
        MyOtpWay::authorizeUsing(fn () => true);

        $this->capture('invalid_phone', $this->postJson('/my-otp/send', ['phone' => 'not-a-number']))
            ->assertStatus(422);

        // Five send requests have now been counted. The throttle and the
        // per-phone cooldown share the cache, and neither's state is needed
        // again, so both are reset together.
        Cache::flush();

        // ---- send route, second budget --------------------------------------
        $this->capture('country_not_allowed', $this->postJson('/my-otp/send', ['phone' => '+441234567890']))
            ->assertStatus(422);

        $this->upstream = fn () => throw new \RuntimeException('internal detail that must not escape');
        $this->capture('service_unavailable', $this->postJson('/my-otp/send', ['phone' => '+9647701234570']))
            ->assertStatus(503);

        // Spend the rest of the budget on distinct numbers, so the refusal
        // below is the throttle's and not the per-phone cooldown's.
        $this->upstreamReturns(202, $sent);
        foreach (['71', '72', '73'] as $suffix) {
            $this->postJson('/my-otp/send', ['phone' => '+96477012345' . $suffix])->assertStatus(202);
        }

        $this->capture('rate_limited', $this->postJson('/my-otp/send', ['phone' => '+9647701234599']))
            ->assertStatus(429);

        // ---- resend route (ceiling 3/min, untouched so far) -----------------
        $this->capture('nothing_to_resend', $this->postJson('/my-otp/resend', ['phone' => '+9647701234580']))
            ->assertStatus(422);

        // ---- verify route (ceiling 10/min) ----------------------------------
        $this->capture('invalid_request', $this->postJson('/my-otp/verify', ['request_id' => 'req-1']))
            ->assertStatus(422);

        // The platform signals the reason in English prose; VerifyResult
        // classifies on substrings, so these are the real strings it matches.
        $this->upstreamReturns(400, ['verified' => false, 'reason' => 'Invalid OTP code.', 'attempts_remaining' => 3]);
        $this->capture('invalid_code', $this->verify())->assertStatus(422);

        $this->upstreamReturns(400, ['verified' => false, 'reason' => 'This code has expired.']);
        $this->capture('expired', $this->verify())->assertStatus(422);

        $this->upstreamReturns(400, ['verified' => false, 'reason' => 'This code was already used.']);
        $this->capture('already_used', $this->verify())->assertStatus(422);

        $this->upstreamReturns(404, ['verified' => false, 'reason' => 'Invalid request ID.']);
        $this->capture('not_found', $this->verify())->assertStatus(422);

        $this->upstreamReturns(429, ['verified' => false, 'reason' => 'Too many attempts.', 'attempts_remaining' => 0]);
        $this->capture('too_many_attempts', $this->verify())->assertStatus(429);

        $this->upstreamReturns(200, ['verified' => true]);
        $this->capture('verify_success', $this->verify())->assertStatus(200);

        $this->assertEveryContractCodeWasRecorded();
        $this->persistOrCompare();
    }

    private function verify(): TestResponse
    {
        return $this->postJson('/my-otp/verify', ['request_id' => 'req-1', 'code' => '000000']);
    }

    /**
     * The vacuity guard's PHP half.
     *
     * A capture list that quietly lost an entry would leave the Dart replay
     * asserting less while still reporting green, and the codes most likely to
     * go missing are the ones no other test can reach. The fixture is the list
     * of what must exist, so it is read rather than restated.
     */
    private function assertEveryContractCodeWasRecorded(): void
    {
        $recorded = array_column($this->captures, 'name');
        $missing  = array_diff(array_keys($this->contract()), $recorded);

        $this->assertSame(
            [],
            array_values($missing),
            'These contract codes were never driven through a real route here, so no client has ever '
            . 'parsed the bytes this package emits for them: ' . implode(', ', $missing),
        );
    }

    private function persistOrCompare(): void
    {
        $encoded = json_encode(
            $this->captures,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ) . "\n";

        $path = $this->goldenPath();

        if (getenv('MY_OTP_WAY_UPDATE_GOLDEN') === '1' || ! file_exists($path)) {
            file_put_contents($path, $encoded);
        }

        $this->assertSame(
            $encoded,
            (string) file_get_contents($path),
            "The bytes this package emits no longer match golden_responses.json, which "
            . 'golden_replay_test.dart feeds to the real Dart client. Re-record with '
            . 'MY_OTP_WAY_UPDATE_GOLDEN=1 and read the diff: every byte that moves is a byte a '
            . 'published app is already parsing, and it cannot be corrected without a store release.',
        );
    }
}
