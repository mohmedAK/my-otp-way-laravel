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
 *
 * It also overrides the *key*, so the three routes stop sharing one counter.
 * See `resolveRequestSignature` below.
 */
final class ProxyThrottle extends ThrottleRequests
{
    /**
     * Give each route its own counter.
     *
     * The parent keys on `domain|ip` — no path, no route name. Three routes
     * carrying three different ceilings therefore increment and read ONE
     * counter and differ only in the number they compare it against, which
     * makes the *smallest* ceiling a cap on all three: send (1), a mistyped
     * verify (2), a second mistyped verify (3), and the user's Resend tap meets
     * resend's ceiling of 3 and is refused. No attacker, no clue in the config.
     *
     * The route name is appended rather than the path so that a host which
     * re-prefixes the group (`my-otp-way.proxy.prefix`) does not silently
     * re-merge or re-split the counters; the name is fixed by this package.
     *
     * Deliberately *not* done by appending a prefix to the middleware argument
     * string in routes/proxy.php: `handle()` treats a 3-argument call whose
     * `$maxAttempts` names a registered limiter as the named-limiter form, and
     * a fourth argument would silently switch that branch off and be read as
     * `$decayMinutes` instead. A host that sets `proxy.throttle.send` to a
     * named limiter is a documented case, and overriding the signature leaves
     * that path intact rather than disabling it.
     *
     * It does not, however, reach into that path. `handleRequestUsingNamedLimiter`
     * builds its keys from the limiter's own `->by()` and never calls this
     * method, so two routes pointed at one named limiter still share a counter
     * — as they should: the host wrote the `->by()` and the host decides the
     * scope. This override fixes the *default* form, which is the one that was
     * silently merging the three routes.
     *
     * Whatever the parent produced stays in the key, so per-user and per-IP
     * behaviour — including key hashing — is unchanged.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return string
     */
    protected function resolveRequestSignature($request)
    {
        $route = $request->route();

        // A route with no name still needs a stable, distinct scope; the URI
        // is the only identity left. `parent::` first: it throws for a request
        // with no route at all, and that guard must keep running.
        return parent::resolveRequestSignature($request) . '|' . (
            ($route?->getName() ?: $route?->uri()) ?? ''
        );
    }

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
