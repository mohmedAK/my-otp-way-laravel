<?php

declare(strict_types=1);

namespace MyOtpWay\Laravel\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use MyOtpWay\Laravel\Exceptions\ConnectionFailedException;
use MyOtpWay\Laravel\Exceptions\InvalidRequestException;
use MyOtpWay\Laravel\Exceptions\MyOtpWayException;
use MyOtpWay\Laravel\Facades\MyOtpWay;
use MyOtpWay\Laravel\Support\ProxyErrorMapper;
use MyOtpWay\Laravel\Support\ResendCooldown;
use Throwable;

class OtpProxyController extends Controller
{
    /**
     * A connection failure gives us no expiry to work from, so the pending-send
     * marker gets the upstream's own OTP lifetime (5 minutes). It is only ever
     * an upper bound on how long /resend stays available for a send we cannot
     * confirm happened.
     */
    private const ASSUMED_OTP_TTL_SECONDS = 300;

    public function send(Request $request): JsonResponse
    {
        return $this->dispatchSend($request, requirePriorSend: false);
    }

    public function resend(Request $request): JsonResponse
    {
        return $this->dispatchSend($request, requirePriorSend: true);
    }

    public function verify(Request $request): JsonResponse
    {
        // The gate runs before validation so an unauthorised caller learns
        // nothing about the endpoint's field shape.
        if (! MyOtpWay::passesAuthorization($request)) {
            return response()->json(['error' => 'forbidden'], 403);
        }

        // Validator::make rather than $request->validate(): the latter throws
        // ValidationException, and Laravel's handler would render its own
        // {"message", "errors"} shape — a body with no `error` key, which every
        // client of this contract reads.
        $validator = Validator::make($request->all(), [
            'request_id' => ['required', 'string'],
            'code'       => ['required', 'string', 'min:4', 'max:8'],
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => 'invalid_request'], 422);
        }

        $validated = $validator->validated();

        try {
            $result = MyOtpWay::otp()->verify($validated['request_id'], $validated['code']);
        } catch (MyOtpWayException $e) {
            return $this->sanitise($e, ['request_id' => $validated['request_id']]);
        } catch (Throwable $e) {
            return $this->sanitiseUnexpected($e, ['request_id' => $validated['request_id']]);
        }

        [$body, $status] = ProxyErrorMapper::forVerify($result);

        return response()->json($body, $status);
    }

    private function dispatchSend(Request $request, bool $requirePriorSend): JsonResponse
    {
        if (! MyOtpWay::passesAuthorization($request)) {
            return response()->json(['error' => 'forbidden'], 403);
        }

        $validator = Validator::make($request->all(), [
            'phone' => ['required', 'string', 'regex:/^\+[1-9]\d{7,14}$/'],
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => 'invalid_phone'], 422);
        }

        $phone = (string) $validator->validated()['phone'];

        if (! $this->countryAllowed($phone)) {
            Log::warning('my-otp-way: recipient country not allowed', [
                'phone'  => $phone,
                'config' => 'my-otp-way.proxy.allowed_country_prefixes',
            ]);

            return response()->json(['error' => 'country_not_allowed'], 422);
        }

        $cooldown = $this->cooldown();

        if ($requirePriorSend && ! $cooldown->hasPendingSend($phone)) {
            return response()->json(['error' => 'nothing_to_resend'], 422);
        }

        if (($wait = $cooldown->remaining($phone)) > 0) {
            return response()->json(['error' => 'resend_too_soon', 'retry_after' => $wait], 429);
        }

        try {
            $result = MyOtpWay::otp()->send(
                to: $phone,
                template: (string) config('my-otp-way.proxy.template', 'verify_code'),
                language: (string) config('my-otp-way.proxy.language', 'ar'),
                channel: (string) config('my-otp-way.proxy.channel', 'whatsapp'),
            );
        } catch (ConnectionFailedException $e) {
            // The one branch where delivery is genuinely unknown: the OTP may
            // already have been sent and charged, which is why a POST is never
            // retried. Skipping the cooldown here would let the per-IP throttle
            // alone decide, i.e. five paid sends a minute to one phone. A clean
            // 402/503 below is different — nothing was sent, so a legitimate
            // user stays free to retry immediately.
            $cooldown->start($phone, self::ASSUMED_OTP_TTL_SECONDS);

            return $this->sanitise($e, ['phone' => $phone]);
        } catch (MyOtpWayException $e) {
            return $this->sanitise($e, ['phone' => $phone]);
        } catch (Throwable $e) {
            return $this->sanitiseUnexpected($e, ['phone' => $phone]);
        }

        $ttl = max(1, $result->expiresAt->getTimestamp() - now()->getTimestamp());
        $cooldown->start($phone, $ttl);

        return response()->json([
            'request_id'          => $result->requestId,
            'expires_at'          => $result->expiresAt->toIso8601String(),
            'resend_available_in' => (int) config('my-otp-way.proxy.resend_cooldown_seconds', 60),
        ], 202);
    }

    /**
     * The real failure goes to the host application's log, where their own
     * operators can read it. The client gets whatever ProxyErrorMapper judged
     * safe, which for anything unrecognised is a blank 503.
     */
    private function sanitise(MyOtpWayException $exception, array $context): JsonResponse
    {
        // The upstream body is the whole point of this log line: every
        // non-recipient validation error becomes a blank 503, so a mistyped
        // MY_OTP_WAY_TEMPLATE would otherwise read only "Validation failed."
        // with no field name to act on.
        $this->log($exception, $context + [
            'status' => $exception->httpStatus,
            'body'   => $exception->body,
        ] + ($exception instanceof InvalidRequestException ? ['errors' => $exception->errors] : []));

        [$body, $status] = ProxyErrorMapper::forSend($exception);

        return response()->json($body, $status);
    }

    /**
     * Anything that is not a MyOtpWayException is unrecognised by definition —
     * a TypeError, a JsonException, a broken cache driver, whatever. None of
     * those carry a safe-to-render body, so the client always gets the same
     * blank 503 that ProxyErrorMapper's own default arm returns.
     */
    private function sanitiseUnexpected(Throwable $exception, array $context): JsonResponse
    {
        $this->log($exception, $context + ['status' => 0]);

        return response()->json(['error' => 'service_unavailable'], 503);
    }

    /** The single Log::error both sanitisers share. */
    private function log(Throwable $exception, array $context): void
    {
        Log::error('my-otp-way request failed', $context + [
            'exception' => $exception::class,
            'message'   => $exception->getMessage(),
        ]);
    }

    private function countryAllowed(string $phone): bool
    {
        // mergeConfigFrom is a shallow array_merge, so a host that publishes and
        // then rewrites its own `proxy` block can drop this key entirely rather
        // than falling back to the package default — the default of ['+964']
        // here, not an empty array, is what keeps an *absent* key safe.
        //
        // An explicitly empty list is a different thing: the deliberate opt-out
        // for developers serving customers abroad.
        $prefixes = (array) config('my-otp-way.proxy.allowed_country_prefixes', ['+964']);

        if ($prefixes === []) {
            return true;
        }

        foreach ($prefixes as $prefix) {
            if (str_starts_with($phone, (string) $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function cooldown(): ResendCooldown
    {
        return new ResendCooldown(
            Cache::store(),
            (int) config('my-otp-way.proxy.resend_cooldown_seconds', 60),
        );
    }
}
