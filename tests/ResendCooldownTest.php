<?php

declare(strict_types=1);

namespace MyOtpWay\Laravel\Tests;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use MyOtpWay\Laravel\Support\ResendCooldown;

class ResendCooldownTest extends TestCase
{
    private function cooldown(int $seconds = 60): ResendCooldown
    {
        return new ResendCooldown(Cache::store(), $seconds);
    }

    public function test_a_fresh_phone_has_no_cooldown_and_no_pending_send(): void
    {
        $cooldown = $this->cooldown();

        $this->assertSame(0, $cooldown->remaining('+9647701234567'));
        $this->assertFalse($cooldown->hasPendingSend('+9647701234567'));
    }

    public function test_starting_the_cooldown_blocks_a_resend_and_records_the_send(): void
    {
        Carbon::setTestNow('2026-07-27 12:00:00');

        $cooldown = $this->cooldown(60);
        $cooldown->start('+9647701234567', otpTtlSeconds: 300);

        $this->assertSame(60, $cooldown->remaining('+9647701234567'));
        $this->assertTrue($cooldown->hasPendingSend('+9647701234567'));
    }

    public function test_the_cooldown_counts_down_and_clears(): void
    {
        Carbon::setTestNow('2026-07-27 12:00:00');
        $cooldown = $this->cooldown(60);
        $cooldown->start('+9647701234567', otpTtlSeconds: 300);

        Carbon::setTestNow('2026-07-27 12:00:45');
        $this->assertSame(15, $cooldown->remaining('+9647701234567'));

        Carbon::setTestNow('2026-07-27 12:01:30');
        $this->assertSame(0, $cooldown->remaining('+9647701234567'));
    }

    /**
     * The pending-send marker outlives the cooldown: a user may legitimately
     * resend at 61 seconds, four minutes before the OTP itself expires.
     */
    public function test_the_pending_send_marker_outlives_the_cooldown(): void
    {
        Carbon::setTestNow('2026-07-27 12:00:00');
        $cooldown = $this->cooldown(60);
        $cooldown->start('+9647701234567', otpTtlSeconds: 300);

        Carbon::setTestNow('2026-07-27 12:02:00');

        $this->assertSame(0, $cooldown->remaining('+9647701234567'));
        $this->assertTrue($cooldown->hasPendingSend('+9647701234567'));
    }

    public function test_two_phones_do_not_share_a_cooldown(): void
    {
        $cooldown = $this->cooldown();
        $cooldown->start('+9647701234567', otpTtlSeconds: 300);

        $this->assertSame(0, $cooldown->remaining('+9647709999999'));
    }

    /**
     * Cache keyspaces get scraped by monitoring tools. A raw phone number in a
     * Redis key is a customer list anybody with read access can page through.
     */
    public function test_the_raw_phone_number_never_appears_in_a_cache_key(): void
    {
        $cooldown = $this->cooldown();
        $cooldown->start('+9647701234567', otpTtlSeconds: 300);

        foreach (['my-otp-way:cooldown:', 'my-otp-way:pending:'] as $prefix) {
            $this->assertNull(Cache::get($prefix . '+9647701234567'));
        }

        $this->assertNotNull(Cache::get('my-otp-way:cooldown:' . sha1('+9647701234567')));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }
}
