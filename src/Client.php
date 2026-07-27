<?php

declare(strict_types=1);

namespace MyOtpWay\Laravel;

use Illuminate\Http\Client\ConnectionException as HttpConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use MyOtpWay\Laravel\Events\MyOtpWayRequestSent;
use MyOtpWay\Laravel\Exceptions\ConnectionFailedException;
use MyOtpWay\Laravel\Exceptions\ExceptionFactory;

final class Client
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly ?string $apiKey,
        private readonly int $timeout = 10,
    ) {
    }

    /** @param  list<int>  $allow  statuses to return rather than throw */
    public function post(string $endpoint, array $payload, array $allow = []): ApiResponse
    {
        return $this->request('POST', $endpoint, $payload, $allow);
    }

    /** @param  list<int>  $allow  statuses to return rather than throw */
    public function get(string $endpoint, array $allow = []): ApiResponse
    {
        return $this->request('GET', $endpoint, [], $allow);
    }

    private function request(string $method, string $endpoint, array $payload, array $allow): ApiResponse
    {
        $startedAt = microtime(true);

        try {
            $response = $this->pending($method)->send($method, $endpoint, $method === 'GET' ? [] : ['json' => $payload]);
        } catch (HttpConnectionException $e) {
            throw new ConnectionFailedException($e->getMessage());
        }

        $body = $response->json() ?? [];

        event(new MyOtpWayRequestSent(
            endpoint: $endpoint,
            httpStatus: $response->status(),
            durationMs: (int) round((microtime(true) - $startedAt) * 1000),
            requestId: isset($body['request_id']) ? (string) $body['request_id'] : null,
        ));

        if ($response->failed() && ! in_array($response->status(), $allow, true)) {
            throw ExceptionFactory::fromStatusAndBody($response->status(), $body);
        }

        return new ApiResponse($response->status(), $body);
    }

    private function pending(string $method): PendingRequest
    {
        $request = Http::baseUrl(rtrim($this->baseUrl, '/'))
            ->withHeaders([
                'X-API-Key' => (string) $this->apiKey,
                'Accept'    => 'application/json',
            ])
            ->timeout($this->timeout);

        // Only GET retries. A POST that times out may already have been
        // delivered and charged, so a retry bills twice for one message.
        return $method === 'GET' ? $request->retry(3, 100, throw: false) : $request;
    }
}
