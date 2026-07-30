# MY-OTP-Way SDK for Laravel

Typed server-side client for the [MY-OTP-Way](https://myotpway.online) API, plus
an optional hardened proxy your mobile app can call without ever holding an
API key.

No method on this SDK returns a raw `array`. Every call either returns a typed
DTO or throws a classified exception.

## Install

```bash
composer require myotpway/laravel-sdk
php artisan vendor:publish --tag=my-otp-way-config
```

```dotenv
MY_OTP_WAY_KEY=your-api-key
MY_OTP_WAY_TEMPLATE=your_approved_template_name
```

Template names are approved per account — check `/dashboard/templates` for
yours. There is no universal default: an unset `MY_OTP_WAY_TEMPLATE` falls
back to an obvious placeholder rather than a name that looks real, so a
misconfigured proxy fails loudly in your log instead of quietly picking a
template that may not exist on your account.

Other environment variables the config file reads (all optional, shown with
their defaults):

| Variable | Default | Purpose |
|---|---|---|
| `MY_OTP_WAY_URL` | `https://myotpway.online/api/v1` | Base URL of the API |
| `MY_OTP_WAY_KEY` | — | Your API key, sent as `X-API-Key` |
| `MY_OTP_WAY_TIMEOUT` | `10` | HTTP timeout in seconds |
| `MY_OTP_WAY_TEMPLATE` | *(placeholder — see above)* | Template the **proxy routes** send (see below) |
| `MY_OTP_WAY_LANGUAGE` | `ar` | Language the proxy routes send |
| `MY_OTP_WAY_CHANNEL` | `whatsapp` | Channel the proxy routes send |

## Server-side use

```php
use MyOtpWay\Laravel\Facades\MyOtpWay;

$result = MyOtpWay::otp()->send(to: '+9647701234567', template: 'your_approved_template_name', language: 'ar');

if (MyOtpWay::otp()->verify($result->requestId, $code)->verified) {
    // the phone number is confirmed
}

MyOtpWay::messages()->send(to: '+9647701234567', template: 'order_shipped', variables: ['name' => 'Ahmed']);

MyOtpWay::balance()->usd; // also ->iqd, ->exchangeRate
```

- `MyOtpWay::otp()->send()` returns a `SendResult` (`requestId`, `expiresAt`).
- `MyOtpWay::otp()->verify()` **never throws for a wrong or expired code** —
  a bad code is an outcome your code branches on, not an exception. It
  returns a `VerifyResult` (`verified`, `failure` — a `VerifyFailure` enum:
  `InvalidCode`, `Expired`, `AlreadyUsed`, `TooManyAttempts`, `NotFound` —
  and `attemptsRemaining`). Only a genuine transport/API failure (bad key,
  network error, upstream 5xx, ...) throws.
- `MyOtpWay::otp()->status($requestId)` returns an `OtpDeliveryStatus`.
- `MyOtpWay::messages()->send()` returns a `MessageResult`; `$variables` as a
  plain PHP list encodes to a positional template (`{{1}}, {{2}}`), a keyed
  array (`['name' => 'Ahmed']`) encodes to a named template — pass whichever
  shape your template expects.
- `MyOtpWay::messages()->status($messageId)` returns a `MessageDeliveryStatus`.
- `MyOtpWay::balance()` returns a `Balance` (`usd`, `iqd`, `exchangeRate`).

### Exceptions

Everything that reaches the API and fails throws a subclass of
`MyOtpWayException` (`httpStatus`, `body`):

| Exception | HTTP status | Extra properties |
|---|---|---|
| `InvalidApiKeyException` | 401 | — |
| `InsufficientBalanceException` | 402 | `balance`, `required` |
| `IpNotAllowedException` | 403 | — |
| `SmsDisabledException` | 403 | — |
| `AccountSuspendedException` | 403 | — (also the catch-all for an unrecognised 403) |
| `TemplateNotFoundException` | 404 | — |
| `InvalidRequestException` | 422 | `errors` |
| `RateLimitException` | 429 | `retryAfter` |
| `NoSenderAvailableException` | 503 | — |
| `ConnectionFailedException` | 0 | thrown when the HTTP call itself fails (timeout, DNS, ...) — no response was ever received, so `httpStatus` is the constructor's default `0`, not a real HTTP status |
| `MyOtpWayException` | whatever the API sent | fallback for a status the SDK doesn't recognise yet |

**A `POST` is never retried.** If the connection drops mid-request the
message may already have been sent and charged, so the client does not retry
it for you — retrying yourself would risk billing twice. `GET` requests (used
by `status()` and `balance()`) retry twice, **on a connection failure only** —
never on an HTTP response. A `401` or a `404` is an answer, not a wobble, so
it comes straight back after one round trip.

## Mobile apps

**Never put your API key in a phone.** Anyone can unpack the app and read it.
Publish the proxy instead and point your mobile client at your own domain:

```php
// routes/api.php — NOT routes/web.php.
// web.php attaches session + CSRF middleware, and a mobile binary can't carry
// a CSRF token, so every POST through it would come back 419.
MyOtpWay::routes();
```

That publishes three public, unauthenticated routes:

| Route | Request | Success |
|---|---|---|
| `POST {prefix}/send` | `{ phone }` | `202 { request_id, expires_at, resend_available_in }` |
| `POST {prefix}/resend` | `{ phone }` | `202 { request_id, expires_at, resend_available_in }` |
| `POST {prefix}/verify` | `{ request_id, code }` | `200 { verified: true }` |

(`prefix` is `my-otp` by default — `config('my-otp-way.proxy.prefix')`.)

They come with:

- **Per-route throttling** (`5,1` / `3,1` / `10,1` by default, configurable
  under `my-otp-way.proxy.throttle`). These routes use the package's own
  `ProxyThrottle` middleware rather than the `throttle` alias: it counts
  identically, but answers `429 {"error": "rate_limited", "retry_after"}`
  instead of Laravel's `{"message": "Too Many Attempts."}`, so a rejection
  every client is expected to hit still speaks the error contract. Your own
  routes are untouched.
- **A per-phone resend cooldown** — 60 seconds by default, configurable via
  `my-otp-way.proxy.resend_cooldown_seconds` — plus a longer-lived "pending
  send" marker so `/resend` can't be called as a second `/send` with a
  looser throttle. See the cache caveat below. The cooldown also starts when
  the call to the API **fails to connect**: delivery is unknown there, the
  OTP may already have been sent and charged, and that is exactly when the
  per-phone gate matters most.
- **A country allow-list** — `/send` and `/resend` reject a phone number that
  doesn't match before any API call is made.
- **A server-chosen template/language/channel** — always the developer's
  own config (`MY_OTP_WAY_TEMPLATE` etc.), never anything the client sends.
  A client-supplied `template` field is silently ignored.
- **Error sanitisation** — the upstream API's balance, key status, and prose
  never reach the phone (see the error contract below).

### Cache-store caveat

The resend cooldown and the pending-send marker are stored via
`Cache::store()` — your application's **default** cache store
(`packages/laravel-sdk/src/Support/ResendCooldown.php`). This matters in
production:

- On the `array` driver, both records vanish between requests (each request
  gets a fresh in-memory store), so the cooldown never actually blocks
  anything and `/resend` never rejects a request as "nothing to resend".
- On the `file` driver behind more than one web server/container, each node
  has its own disk, so the cooldown and pending-send marker don't shard
  across nodes — a client hitting a different node than its `/send` call
  sees no cooldown and no record of the prior send.

**Point your default cache at a shared store (Redis) in production** so the
cooldown and the pending-send marker are visible to every node handling
these routes.

### Country allow-list

Controlled by `my-otp-way.proxy.allowed_country_prefixes`, checked against
the phone's `+` prefix before any API call:

- **Default:** `['+964']` — only Iraqi numbers.
- **An explicitly empty array (`[]`) allows every country.** This is a
  deliberate opt-out for developers serving customers abroad, and is
  distinct from an absent/missing key, which still fails closed to the
  `['+964']` default (a host that publishes the config and then overwrites
  the whole `proxy` block, dropping this key, does not get "open" by
  accident).
- A rejected number returns `422 {"error": "country_not_allowed"}` before any
  request reaches the API.

### Error contract

**Every non-2xx response from these three routes carries an `error` key.**
That includes the two cases the framework would otherwise answer in its own
shape: a request that fails local validation (a mistyped phone number is the
most common failure in an OTP flow) and a throttle rejection. A client can
read `body['error']` unconditionally.

`/send` and `/resend` accept only `phone` (`regex:/^\+[1-9]\d{7,14}$/`);
`/verify` accepts `request_id` and `code` (4–8 chars). Anything else in the
body is ignored.

| `error` | HTTP status | Extra fields | When |
|---|---|---|---|
| `forbidden` | 403 | — | `MyOtpWay::authorizeUsing()` callback rejected the request. Checked **before** validation, so an unauthorised caller learns nothing about the field shape |
| `invalid_phone` | 422 | — | `/send`, `/resend` — `phone` missing or failing the regex, *or* the upstream API rejected the recipient specifically |
| `country_not_allowed` | 422 | — | `/send`, `/resend` — phone doesn't match the allow-list |
| `nothing_to_resend` | 422 | — | `/resend` called with no prior `/send` for this phone |
| `resend_too_soon` | 429 | `retry_after` (seconds) | `/send`, `/resend` — still inside the per-phone cooldown window |
| `invalid_request` | 422 | — | `/verify` — `request_id` or `code` missing or malformed |
| `rate_limited` | 429 | `retry_after` (seconds) | the per-IP throttle on any of the three routes, *or* the upstream API itself rate-limiting the request |
| `service_unavailable` | 503 | — | **everything else** — invalid/revoked key, insufficient balance, suspended account, IP not allowed, SMS disabled, template not found, a non-recipient validation error, no sender available, a connection failure, or any unrecognised exception. Nothing here ever reveals *why* |
| `invalid_code` | 422 | `verified: false`, `attempts_remaining` when the API reports it | `/verify` — wrong code |
| `expired` | 422 | `verified: false` | `/verify` — OTP expired |
| `already_used` | 422 | `verified: false` | `/verify` — OTP already verified |
| `not_found` | 422 | `verified: false` | `/verify` — unknown `request_id` |
| `too_many_attempts` | 429 | `verified: false` | `/verify` — attempt limit reached (a lockout, not a one-off wrong code) |

A successful `/verify` is the one response with no `error` key at all:
`200 {"verified": true}`.

`service_unavailable` is deliberately the default arm: an exception this SDK
doesn't recognise yet still degrades to it rather than passing through,
which is what keeps a forgotten case from ever leaking a balance or an
internal message. Every sanitised failure is written to **your** log via
`Log::error` with the real upstream body and, for a validation failure, its
`errors` bag — so a mistyped `MY_OTP_WAY_TEMPLATE` is diagnosable even though
the client only ever saw a blank 503.

Restrict who may call the proxy routes at all:

```php
MyOtpWay::authorizeUsing(fn ($request) => $request->hasValidSignature());
```

By default every request is allowed — OTP send typically happens before
login (e.g. on a registration screen), so requiring your own app's auth here
would break that flow.

The callback lives on the `my-otp-way` singleton, so it lasts exactly as long
as the container does. Register it once in your `AppServiceProvider::boot()`;
a test that registers its own is reset with the container, like anything else
bound there.

## Testing

```php
$fake = MyOtpWay::fake();

// somewhere in your app: MyOtpWay::otp()->send(...)
$this->post('/register', ['phone' => '+9647701234567']);

$fake->assertSent('+9647701234567');
$this->post('/verify', ['code' => $fake->codeFor('+9647701234567')]);
```

`MyOtpWay::fake()` returns a `MyOtpWayFake` and makes `MyOtpWay::otp()`
return it instead of the real `OtpResource` — no HTTP call is made. It
provides:

- `send()` / `verify()` — a working in-memory round trip (a real random code
  is generated and must match to verify).
- `assertSent(string $to)` — asserts an OTP was sent to that number.
- `assertNothingSent()` — asserts no OTP was sent at all.
- `codeFor(string $to)` — returns the code the fake "sent" to that number
  (the last one, if sent more than once), so a feature test can complete the
  verify step without knowing a real code.
- `shouldFailWith(MyOtpWayException $exception)` — makes the **next and all
  subsequent** `send()` calls throw that exception. This affects `send()`
  only: `verify()` never throws on the fake either, mirroring the real
  `OtpResource`.

**Note:** `MyOtpWay::fake()` only replaces `otp()`. `MyOtpWay::messages()`
and `MyOtpWay::balance()` still hit the real HTTP client, so fake those with
Laravel's own `Http::fake()` if your test path touches them.

## Observability

```php
Event::listen(MyOtpWayRequestSent::class, fn ($e) => Log::info($e->endpoint, [
    'status' => $e->httpStatus, 'ms' => $e->durationMs,
]));
```

`MyOtpWayRequestSent` fires after every call the client makes (`endpoint`,
`httpStatus`, `durationMs`, `requestId`). **It carries no recipient and no
OTP code** — that's deliberate: this event is likely to end up in your
application's own logs, and a phone number or an OTP code is not something
this SDK puts there for you.
