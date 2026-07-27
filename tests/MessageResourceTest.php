<?php

declare(strict_types=1);

namespace MyOtpWay\Laravel\Tests;

use Illuminate\Support\Facades\Http;
use MyOtpWay\Laravel\Facades\MyOtpWay;

class MessageResourceTest extends TestCase
{
    public function test_send_forwards_named_variables_untouched(): void
    {
        Http::fake(['api.test/*' => Http::response([
            'message' => 'Message sent successfully.',
            'data' => ['message_id' => 'msg-1', 'template' => 'order_shipped', 'language' => 'ar', 'status' => 'queued'],
        ], 202)]);

        $result = MyOtpWay::messages()->send(
            to: '+9647701234567',
            template: 'order_shipped',
            variables: ['name' => 'Ahmed', 'order' => '1042'],
            language: 'ar',
        );

        $this->assertSame('msg-1', $result->messageId);
        $this->assertSame('queued', $result->status);

        Http::assertSent(fn ($request) => $request->url() === 'https://api.test/v1/message/send'
            && $request['variables'] === ['name' => 'Ahmed', 'order' => '1042']);
    }

    /**
     * A positional template needs a JSON array, not an object. Passing a PHP
     * list through untouched is what preserves that distinction on the wire.
     */
    public function test_send_preserves_a_positional_variable_list(): void
    {
        Http::fake(['api.test/*' => Http::response([
            'message' => 'Message sent successfully.',
            'data' => ['message_id' => 'msg-2', 'template' => 'legacy', 'language' => 'en', 'status' => 'queued'],
        ], 202)]);

        MyOtpWay::messages()->send(to: '+9647701234567', template: 'legacy', variables: ['Ahmed', '1042']);

        Http::assertSent(fn ($request) => $request['variables'] === ['Ahmed', '1042']);
    }

    public function test_status_returns_a_delivery_status(): void
    {
        Http::fake(['api.test/*' => Http::response([
            'message_id' => 'msg-1', 'status' => 'delivered', 'template' => 'order_shipped',
            'recipient' => '+9647701234567', 'error_code' => null, 'error_message' => null,
            'sent_at' => '2026-07-27T12:00:00+00:00', 'created_at' => '2026-07-27T11:59:58+00:00',
        ], 200)]);

        $this->assertSame('delivered', MyOtpWay::messages()->status('msg-1')->status);

        Http::assertSent(fn ($request) => $request->url() === 'https://api.test/v1/message/msg-1/status');
    }

    public function test_balance_returns_both_currencies(): void
    {
        Http::fake(['api.test/*' => Http::response([
            'api_key' => ['prefix' => 'mow_abc', 'name' => 'Production'],
            'balance' => ['usd' => 32.7977, 'iqd' => 42965, 'exchange_rate' => 1310],
        ], 200)]);

        $balance = MyOtpWay::balance();

        $this->assertSame(32.7977, $balance->usd);
        $this->assertSame(42965, $balance->iqd);
        $this->assertSame(1310.0, $balance->exchangeRate);

        Http::assertSent(fn ($request) => $request->url() === 'https://api.test/v1/balance');
    }
}
