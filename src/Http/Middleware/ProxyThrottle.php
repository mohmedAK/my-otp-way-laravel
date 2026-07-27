<?php

declare(strict_types=1);

namespace MyOtpWay\Laravel\Http\Middleware;

use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Routing\Middleware\ThrottleRequests;

/**
 * Laravel's throttle, restated in the proxy's own error contract.
 *
 * `ThrottleRequests` renders `{"message": "Too Many Attempts."}` — a body with
 * no `error` key, which is the only key a client of these routes reads. The
 * per-IP throttle is defence layer 2 and fires in normal operation, so it has
 * to speak the contract.
 *
 * This overrides the response, not the counting: `buildException` already
 * supports a response callback (that is how named limiters customise their
 * 429), and the parent still computes the key, the retry-after and the
 * `X-RateLimit-*` headers. A host that configures a named limiter with its own
 * callback keeps it — we only fill in the default.
 *
 * A middleware wrapped *around* the throttle cannot do this: the routing
 * pipeline renders a throwing stage through the exception handler, so the
 * wrapper would only ever see an already-rendered 429, never the exception.
 */
final class ProxyThrottle extends ThrottleRequests
{
    /**
     * @param  \Illuminate\Http\Request  $request
     * @param  string  $key
     * @param  int  $maxAttempts
     * @param  callable|null  $responseCallback
     * @return ThrottleRequestsException|HttpResponseException
     */
    protected function buildException($request, $key, $maxAttempts, $responseCallback = null)
    {
        return parent::buildException(
            $request,
            $key,
            $maxAttempts,
            $responseCallback ?? fn ($request, array $headers) => response()->json([
                'error'       => 'rate_limited',
                'retry_after' => (int) ($headers['Retry-After'] ?? 0),
            ], 429, $headers),
        );
    }
}
