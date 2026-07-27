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

    public function test_a_malformed_phone_is_rejected_before_any_call(): void
    {
        Http::fake();

        $this->postJson('/my-otp/send', ['phone' => 'not-a-number'])->assertStatus(422);

        Http::assertNothingSent();
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
