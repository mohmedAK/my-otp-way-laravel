<?php

declare(strict_types=1);

namespace MyOtpWay\Laravel\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use MyOtpWay\Laravel\Exceptions\MyOtpWayException;
use MyOtpWay\Laravel\Facades\MyOtpWay;
use MyOtpWay\Laravel\Support\ProxyErrorMapper;
use MyOtpWay\Laravel\Support\ResendCooldown;
use Throwable;

class OtpProxyController extends Controller
{
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
        $validated = $request->validate([
            'request_id' => ['required', 'string'],
            'code'       => ['required', 'string', 'min:4', 'max:8'],
        ]);

        if (! MyOtpWay::passesAuthorization($request)) {
            return response()->json(['error' => 'forbidden'], 403);
        }

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
        $validated = $request->validate([
            'phone' => ['required', 'string', 'regex:/^\+[1-9]\d{7,14}$/'],
        ]);

        $phone = $validated['phone'];

        if (! MyOtpWay::passesAuthorization($request)) {
            return response()->json(['error' => 'forbidden'], 403);
        }

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
                template: (string) config('my-otp-way.proxy.template'),
                language: (string) config('my-otp-way.proxy.language'),
                channel: (string) config('my-otp-way.proxy.channel'),
            );
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
            'resend_available_in' => (int) config('my-otp-way.proxy.resend_cooldown_seconds'),
        ], 202);
    }

    /**
     * The real failure goes to the host application's log, where their own
     * operators can read it. The client gets whatever ProxyErrorMapper judged
     * safe, which for anything unrecognised is a blank 503.
     */
    private function sanitise(MyOtpWayException $exception, array $context): JsonResponse
    {
        Log::error('my-otp-way request failed', $context + [
            'exception' => $exception::class,
            'status'    => $exception->httpStatus,
            'message'   => $exception->getMessage(),
        ]);

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
        Log::error('my-otp-way request failed', $context + [
            'exception' => $exception::class,
            'status'    => 0,
            'message'   => $exception->getMessage(),
        ]);

        return response()->json(['error' => 'service_unavailable'], 503);
    }

    private function countryAllowed(string $phone): bool
    {
        $prefixes = (array) config('my-otp-way.proxy.allowed_country_prefixes', []);

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
