<?php

declare(strict_types=1);

namespace MyOtpWay\Laravel\Tests;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use MyOtpWay\Laravel\Facades\MyOtpWay;

class ProxyRoutesTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        MyOtpWay::routes();
    }

    private function fakeSendSuccess(): void
    {
        Http::fake(['api.test/*' => Http::response(['request_id' => 'req-1', 'expires_in' => 300], 202)]);
    }

    public function test_send_returns_the_contract_shape(): void
    {
        $this->fakeSendSuccess();

        $this->postJson('/my-otp/send', ['phone' => '+9647701234567'])
            ->assertStatus(202)
            ->assertJsonStructure(['request_id', 'expires_at', 'resend_available_in'])
            ->assertJsonPath('request_id', 'req-1')
            ->assertJsonPath('resend_available_in', 60);
    }

    /**
     * The template is the whole cost lever. If a client could name it, an
     * attacker would pick the priciest marketing template on the account.
     */
    public function test_the_client_cannot_choose_the_template(): void
    {
        $this->fakeSendSuccess();
        config()->set('my-otp-way.proxy.template', 'verify_code');

        $this->postJson('/my-otp/send', ['phone' => '+9647701234567', 'template' => 'black_friday_blast'])
            ->assertStatus(202);

        Http::assertSent(fn ($request) => $request['template'] === 'verify_code');
    }

    /**
     * The body matters as much as the status. A mistyped phone number is the
     * most common failure in an OTP flow, and Laravel's own validation shape
     * ({"message", "errors"}) carries no `error` key — a client reading the
     * contract would fall through to a generic "service unavailable" and tell
     * the user the service is down.
     */
    public function test_a_malformed_phone_is_rejected_before_any_call(): void
    {
        Http::fake();

        $this->postJson('/my-otp/send', ['phone' => 'not-a-number'])
            ->assertStatus(422)
            ->assertExactJson(['error' => 'invalid_phone']);

        Http::assertNothingSent();
    }

    public function test_a_missing_phone_is_rejected_with_the_same_envelope(): void
    {
        Http::fake();

        $this->postJson('/my-otp/send', [])
            ->assertStatus(422)
            ->assertExactJson(['error' => 'invalid_phone']);

        Http::assertNothingSent();
    }

    public function test_verify_validation_failures_speak_the_error_contract(): void
    {
        Http::fake();

        $this->postJson('/my-otp/verify', ['request_id' => 'req-1'])
            ->assertStatus(422)
            ->assertExactJson(['error' => 'invalid_request']);

        $this->postJson('/my-otp/verify', ['request_id' => 'req-1', 'code' => '12'])
            ->assertStatus(422)
            ->assertExactJson(['error' => 'invalid_request']);

        Http::assertNothingSent();
    }

    /**
     * The per-IP throttle is defence layer 2 and fires in normal operation, so
     * its 429 has to speak the contract rather than Laravel's own
     * {"message": "Too Many Attempts."}.
     */
    public function test_the_throttle_speaks_the_error_contract(): void
    {
        $this->fakeSendSuccess();

        // Distinct numbers: the per-phone cooldown would otherwise answer first.
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/my-otp/send', ['phone' => '+96477012345' . (10 + $i)])->assertStatus(202);
        }

        $response = $this->postJson('/my-otp/send', ['phone' => '+9647701234599'])
            ->assertStatus(429)
            ->assertJsonPath('error', 'rate_limited');

        $this->assertIsInt($response->json('retry_after'));
        $this->assertGreaterThan(0, $response->json('retry_after'));
        $this->assertSame(['error', 'retry_after'], array_keys($response->json()));

        Http::assertSentCount(5);
    }

    /**
     * The one case where the OTP may already have been sent and charged. It is
     * why a POST is never retried, and it must not also be the one case that
     * skips the per-phone gate — with throttle:5,1 an upstream wobble would
     * otherwise allow five paid sends a minute to one number.
     */
    public function test_a_connection_failure_still_starts_the_cooldown(): void
    {
        Http::fake(['api.test/*' => fn () => throw new \GuzzleHttp\Exception\ConnectException(
            'timed out',
            new \GuzzleHttp\Psr7\Request('POST', 'https://api.test/v1/otp/send'),
        )]);

        $this->postJson('/my-otp/send', ['phone' => '+9647701234567'])
            ->assertStatus(503)
            ->assertExactJson(['error' => 'service_unavailable']);

        $this->postJson('/my-otp/send', ['phone' => '+9647701234567'])
            ->assertStatus(429)
            ->assertJsonPath('error', 'resend_too_soon');

        Http::assertSentCount(1);
    }

    /**
     * A clean 402 is the opposite case: nothing was sent, nothing was charged,
     * so a legitimate user must stay free to retry immediately.
     */
    public function test_a_clean_upstream_refusal_does_not_start_the_cooldown(): void
    {
        Http::fake(['api.test/*' => Http::response(['message' => 'Insufficient API key balance.'], 402)]);

        $this->postJson('/my-otp/send', ['phone' => '+9647701234567'])->assertStatus(503);

        $this->postJson('/my-otp/send', ['phone' => '+9647701234567'])
            ->assertStatus(503)
            ->assertExactJson(['error' => 'service_unavailable']);

        Http::assertSentCount(2);
    }

    public function test_a_disallowed_country_is_rejected_before_any_call(): void
    {
        Http::fake();
        config()->set('my-otp-way.proxy.allowed_country_prefixes', ['+964']);

        $this->postJson('/my-otp/send', ['phone' => '+441234567890'])
            ->assertStatus(422)
            ->assertJsonPath('error', 'country_not_allowed');

        Http::assertNothingSent();
    }

    /**
     * mergeConfigFrom is a shallow array_merge: a host that publishes and then
     * rewrites its own `proxy` block can drop `allowed_country_prefixes`
     * entirely. That must fail closed (default ['+964']), never open.
     */
    public function test_an_absent_allow_list_key_still_blocks_disallowed_countries(): void
    {
        Http::fake();
        config()->set('my-otp-way.proxy', ['prefix' => 'my-otp', 'template' => 'verify_code']);

        $this->postJson('/my-otp/send', ['phone' => '+441234567890'])
            ->assertStatus(422)
            ->assertJsonPath('error', 'country_not_allowed');

        Http::assertNothingSent();
    }

    /**
     * An explicitly empty list is the documented opt-out for developers who
     * serve customers abroad, distinct from an absent key (which stays safe).
     */
    public function test_an_explicitly_empty_allow_list_permits_every_country(): void
    {
        $this->fakeSendSuccess();
        config()->set('my-otp-way.proxy.allowed_country_prefixes', []);

        $this->postJson('/my-otp/send', ['phone' => '+441234567890'])->assertStatus(202);

        Http::assertSentCount(1);
    }

    public function test_a_second_send_within_the_cooldown_is_refused(): void
    {
        $this->fakeSendSuccess();

        $this->postJson('/my-otp/send', ['phone' => '+9647701234567'])->assertStatus(202);

        $this->postJson('/my-otp/send', ['phone' => '+9647701234567'])
            ->assertStatus(429)
            ->assertJsonPath('error', 'resend_too_soon');

        Http::assertSentCount(1);
    }

    public function test_resend_without_a_prior_send_is_refused(): void
    {
        Http::fake();

        $this->postJson('/my-otp/resend', ['phone' => '+9647701234567'])
            ->assertStatus(422)
            ->assertJsonPath('error', 'nothing_to_resend');

        Http::assertNothingSent();
    }

    /**
     * The whole reason the proxy exists. The upstream 402 names the developer's
     * balance; the phone must see nothing but a blank failure.
     */
    public function test_an_insufficient_balance_never_reaches_the_client(): void
    {
        Log::spy();
        Http::fake(['api.test/*' => Http::response([
            'message' => 'Insufficient API key balance.', 'balance' => 12.4, 'required' => 0.02,
        ], 402)]);

        $response = $this->postJson('/my-otp/send', ['phone' => '+9647701234567']);

        $response->assertStatus(503)->assertExactJson(['error' => 'service_unavailable']);
        $this->assertStringNotContainsString('12.4', $response->getContent());

        Log::shouldHaveReceived('error')->once();
    }

    /**
     * The other half of sanitisation: what the client is denied, the developer's
     * own log must still get. A misconfigured MY_OTP_WAY_TEMPLATE surfaces only
     * as a blank 503, so without the upstream body the log reads "Validation
     * failed." with no field name to act on.
     */
    public function test_the_sanitised_log_carries_the_real_upstream_body(): void
    {
        Log::spy();
        Http::fake(['api.test/*' => Http::response([
            'message' => 'Validation failed.', 'errors' => ['template' => ['The selected template is invalid.']],
        ], 422)]);

        $this->postJson('/my-otp/send', ['phone' => '+9647701234567'])
            ->assertStatus(503)
            ->assertExactJson(['error' => 'service_unavailable']);

        Log::shouldHaveReceived('error')->once()->withArgs(function ($message, $context) {
            return $context['body']['errors']['template'][0] === 'The selected template is invalid.'
                && $context['errors']['template'][0] === 'The selected template is invalid.'
                && $context['status'] === 422;
        });
    }

    public function test_verify_returns_true_on_success(): void
    {
        Http::fake(['api.test/*' => Http::response(['verified' => true], 200)]);

        $this->postJson('/my-otp/verify', ['request_id' => 'req-1', 'code' => '123456'])
            ->assertStatus(200)
            ->assertExactJson(['verified' => true]);
    }

    public function test_a_wrong_code_returns_a_machine_code_and_the_attempts_left(): void
    {
        Http::fake(['api.test/*' => Http::response([
            'verified' => false, 'reason' => 'Invalid OTP code.', 'attempts_remaining' => 3,
        ], 400)]);

        $this->postJson('/my-otp/verify', ['request_id' => 'req-1', 'code' => '000000'])
            ->assertStatus(422)
            ->assertJsonPath('error', 'invalid_code')
            ->assertJsonPath('attempts_remaining', 3);
    }

    public function test_the_authorization_gate_can_refuse_a_request(): void
    {
        // A single fake registered up front: Http::fake() calls accumulate
        // stub callbacks rather than replacing them, and resolution is
        // first-match-wins by registration order, so a second, later
        // Http::fake() call here would never win over an earlier catch-all.
        // The first request never reaches HTTP at all (the gate rejects it
        // before any client call), so one registration covers both requests.
        $this->fakeSendSuccess();
        MyOtpWay::authorizeUsing(fn ($request) => $request->header('X-App-Token') === 'let-me-in');

        $this->postJson('/my-otp/send', ['phone' => '+9647701234567'])->assertStatus(403);

        $this->withHeader('X-App-Token', 'let-me-in')
            ->postJson('/my-otp/send', ['phone' => '+9647701234567'])
            ->assertStatus(202);
    }

    public function test_the_gate_allows_everything_by_default(): void
    {
        $this->fakeSendSuccess();

        $this->postJson('/my-otp/send', ['phone' => '+9647701234567'])->assertStatus(202);
    }

    public function test_an_unexpected_throwable_is_also_sanitised(): void
    {
        Log::spy();
        Http::fake(['api.test/*' => fn () => throw new \RuntimeException('internal detail that must not escape')]);

        $response = $this->postJson('/my-otp/send', ['phone' => '+9647701234567']);

        $response->assertStatus(503)->assertExactJson(['error' => 'service_unavailable']);
        $this->assertStringNotContainsString('internal detail', $response->getContent());

        Log::shouldHaveReceived('error')->once();
    }
}
