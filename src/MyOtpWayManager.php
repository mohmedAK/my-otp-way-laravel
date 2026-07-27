<?php

declare(strict_types=1);

namespace MyOtpWay\Laravel;

use Illuminate\Http\Request;
use MyOtpWay\Laravel\Data\Balance;
use MyOtpWay\Laravel\Resources\MessageResource;
use MyOtpWay\Laravel\Resources\OtpResource;
use MyOtpWay\Laravel\Testing\MyOtpWayFake;

class MyOtpWayManager
{
    private ?Client $client = null;

    private ?MyOtpWayFake $fake = null;

    /** @var (callable(Request): bool)|null */
    private static $authorizeCallback = null;

    public function __construct(private readonly array $config)
    {
    }

    public function config(?string $key = null): mixed
    {
        return $key === null ? $this->config : data_get($this->config, $key);
    }

    public function client(): Client
    {
        return $this->client ??= new Client(
            baseUrl: (string) $this->config['base_url'],
            apiKey: $this->config['api_key'] ?? null,
            timeout: (int) ($this->config['timeout'] ?? 10),
        );
    }

    public function otp(): OtpResource|MyOtpWayFake
    {
        return $this->fake ?? new OtpResource($this->client());
    }

    public function fake(): MyOtpWayFake
    {
        return $this->fake = new MyOtpWayFake();
    }

    public function messages(): MessageResource
    {
        return new MessageResource($this->client());
    }

    public function balance(): Balance
    {
        return Balance::fromArray($this->client()->get('balance')->body);
    }

    /**
     * Registers the three public proxy routes. Call from routes/api.php —
     * not routes/web.php, whose session/CSRF middleware would 419 every
     * POST from a mobile client that can't carry a CSRF token.
     */
    public function routes(): void
    {
        require __DIR__ . '/../routes/proxy.php';
    }

    /** @param  callable(Request): bool  $callback */
    public function authorizeUsing(callable $callback): void
    {
        self::$authorizeCallback = $callback;
    }

    /**
     * Allows everything by default. OTP send usually happens before login, on
     * the registration screen, so requiring the host application's own auth
     * would break the primary use case.
     */
    public function passesAuthorization(Request $request): bool
    {
        return self::$authorizeCallback === null || (bool) call_user_func(self::$authorizeCallback, $request);
    }

    /** Test helper: forget any callback registered by authorizeUsing(). */
    public function forgetAuthorization(): void
    {
        self::$authorizeCallback = null;
    }
}
