<?php

declare(strict_types=1);

namespace MyOtpWay\Laravel\Tests;

use MyOtpWay\Laravel\Data\VerifyResult;
use MyOtpWay\Laravel\Enums\VerifyFailure;
use MyOtpWay\Laravel\Exceptions\InvalidRequestException;
use MyOtpWay\Laravel\Exceptions\MyOtpWayException;
use MyOtpWay\Laravel\Exceptions\RateLimitException;
use MyOtpWay\Laravel\Support\ProxyErrorMapper;
use PHPUnit\Framework\TestCase as BaseTestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * The Flutter package is written against a fixture it shares with this one.
 * Neither side can import the other, so the fixture is the only thing keeping
 * them honest — this test proves the PHP half still emits what it promises.
 *
 * Deliberately a plain PHPUnit TestCase rather than an Orchestra one: nothing
 * here needs a booted container, and no Illuminate helper is called, so the
 * contract stays assertable even if the testbench harness breaks. The half of
 * the contract that only exists as a real HTTP response — every code the proxy
 * controller writes, and the `retry_after` it puts beside `resend_too_soon` —
 * is asserted in {@see ProxyRoutesTest}, which reads the same fixture through
 * the same trait.
 */
class ContractFixtureTest extends BaseTestCase
{
    use SharedContract;

    private const SOURCE = __DIR__ . '/../src';

    /**
     * `extra` fields whose only real emission site is an HTTP response, and so
     * cannot be driven from a container-free test. Each names the
     * {@see ProxyRoutesTest} method that drives it for real; that method's
     * existence is asserted, so deleting or renaming it fails here rather than
     * quietly leaving the field unproven.
     */
    private const EXTRAS_DRIVEN_IN_PROXY_ROUTES_TEST = [
        'resend_too_soon' => 'test_the_resend_cooldown_refusal_matches_the_shared_contract',
    ];

    /**
     * `error` keys in `src/` whose value is an expression rather than a string
     * literal, so the scanner cannot read them. Keyed by the exact source
     * expression: change the expression and the entry stops matching, which
     * re-opens the failure rather than silently keeping the exemption.
     *
     * An entry here is a promise that the codes the expression can produce are
     * asserted some other way.
     */
    private const DYNAMIC_EMISSIONS = [
        '$result->failure?->value ?? \'invalid_code\''
            => 'ProxyErrorMapper::forVerify — every value it can produce is driven by '
             . 'everyMapperEmission(), which iterates VerifyFailure::cases().',
    ];

    /**
     * Every error the mapper can produce, as `[body, status]`. Both mapper
     * entry points are covered, and `forVerify` is driven from
     * `VerifyFailure::cases()` so a new enum case cannot be added without
     * this list growing with it.
     *
     * @return list<array{0: array<string, mixed>, 1: int}>
     */
    private function everyMapperEmission(): array
    {
        $emissions = [];

        foreach ([
            new RateLimitException('too fast', 900),
            new InvalidRequestException('Validation failed.', ['to' => ['bad']]),
            new MyOtpWayException('anything else', 500),
        ] as $exception) {
            $emissions[] = ProxyErrorMapper::forSend($exception);
        }

        foreach (VerifyFailure::cases() as $failure) {
            $emissions[] = ProxyErrorMapper::forVerify(new VerifyResult(verified: false, failure: $failure));
        }

        // The `?? 'invalid_code'` arm: a failed verify the client could not
        // classify still has to speak the contract.
        $emissions[] = ProxyErrorMapper::forVerify(new VerifyResult(verified: false));

        return $emissions;
    }

    public function test_the_mapper_emits_only_codes_the_contract_lists(): void
    {
        $contract = $this->contract();

        foreach ($this->everyMapperEmission() as [$body]) {
            $this->assertArrayHasKey(
                $body['error'],
                $contract,
                "ProxyErrorMapper emits \"{$body['error']}\" but the shared contract fixture does not list it. "
                . 'Add it to the fixture and to the Flutter package before shipping.',
            );
        }
    }

    public function test_the_statuses_match_the_contract(): void
    {
        $contract = $this->contract();

        foreach ($this->everyMapperEmission() as [$body, $status]) {
            $code = $body['error'];

            $this->assertArrayHasKey($code, $contract, "ProxyErrorMapper emits an unlisted \"{$code}\".");

            $this->assertSame(
                $contract[$code]['status'],
                $status,
                "The contract says \"{$code}\" is HTTP {$contract[$code]['status']}, but the mapper returned {$status}.",
            );
        }
    }

    /**
     * The fixture freezes a field NAME beside three of the codes, and the
     * Flutter package reads that exact name — `ResendTooSoon.availableIn` is
     * built from `retry_after`, `WrongCode.attemptsRemaining` from
     * `attempts_remaining`. Proving the Dart half *reads* the name proves
     * nothing about whether this half still *writes* it: renaming the key here
     * leaves every published app building the exception with a null forever,
     * and a published app takes an app-store release to fix.
     *
     * So every fixture entry that declares an `extra` must be driven through a
     * real emission, with the key name read from the fixture — hard-coding it
     * here would just be a fifth copy of the contract.
     */
    public function test_every_extra_the_contract_promises_is_actually_written(): void
    {
        $contract = $this->contract();

        /** @var array<string, array<string, mixed>> $driven */
        $driven = [];

        [$body] = ProxyErrorMapper::forSend(new RateLimitException('too fast', 900));
        $driven[$body['error']] = $body;

        // attemptsRemaining is deliberately non-null: the mapper omits the key
        // when the upstream did not send a count, so the only honest assertion
        // is "when it is known, it goes out under the contract's name".
        [$body] = ProxyErrorMapper::forVerify(new VerifyResult(
            verified: false,
            failure: VerifyFailure::InvalidCode,
            attemptsRemaining: 3,
        ));
        $driven[$body['error']] = $body;

        $promised = 0;

        foreach ($contract as $code => $entry) {
            $extra = $entry['extra'] ?? null;

            if ($extra === null) {
                continue;
            }

            $promised++;

            if (isset(self::EXTRAS_DRIVEN_IN_PROXY_ROUTES_TEST[$code])) {
                $method = self::EXTRAS_DRIVEN_IN_PROXY_ROUTES_TEST[$code];

                $this->assertTrue(
                    method_exists(ProxyRoutesTest::class, $method),
                    "The contract says \"{$code}\" carries \"{$extra}\", and this test defers that proof to "
                    . "ProxyRoutesTest::{$method}() — which no longer exists. Restore it, or drive "
                    . "\"{$code}\" here.",
                );

                continue;
            }

            $this->assertArrayHasKey(
                $code,
                $driven,
                "The shared contract fixture says \"{$code}\" carries \"{$extra}\", but nothing drives a real "
                . "\"{$code}\" emission. Add one here, or name the ProxyRoutesTest method that does in "
                . 'EXTRAS_DRIVEN_IN_PROXY_ROUTES_TEST. An unproven `extra` is how a client ends up reading a '
                . 'key the server stopped writing.',
            );

            $this->assertArrayHasKey(
                $extra,
                $driven[$code],
                "The shared contract fixture says \"{$code}\" carries \"{$extra}\", but this package wrote ["
                . implode(', ', array_keys($driven[$code]))
                . ']. The Flutter package reads that exact key — renaming it here leaves every published app '
                . 'with a null field and needs an app-store release to undo.',
            );
        }

        $this->assertGreaterThan(
            0,
            $promised,
            'No fixture entry declares an `extra`, so this test asserted nothing. Either the fixture lost its '
            . '`extra` fields or the loop is broken.',
        );
    }

    public function test_a_successful_verify_matches_the_contract(): void
    {
        $decoded = json_decode(
            (string) file_get_contents($this->contractFixturePath()),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        [$body, $status] = ProxyErrorMapper::forVerify(new VerifyResult(verified: true));

        $this->assertSame($decoded['success']['verify']['status'], $status);
        $this->assertSame($decoded['success']['verify']['keys'], array_keys($body));
        $this->assertTrue($body['verified']);
    }

    /**
     * The mapper is not the only thing that speaks this contract: the proxy
     * controller writes five of the thirteen codes itself (`forbidden`,
     * `invalid_request`, `country_not_allowed`, `nothing_to_resend`,
     * `resend_too_soon`) and `ProxyThrottle` writes a sixth. Those never pass
     * through `ProxyErrorMapper`, so without this scan a rename in the
     * controller would break the Flutter package with no PHP test failing.
     */
    public function test_the_contract_and_this_package_list_the_same_codes(): void
    {
        $contract = array_keys($this->contract());
        $emitted  = $this->codesEmittedBySource();

        sort($contract);
        sort($emitted);

        $this->assertSame(
            $contract,
            $emitted,
            "The shared contract fixture and this package no longer agree on the set of error codes.\n"
            . 'In the fixture only: ' . implode(', ', array_diff($contract, $emitted)) . "\n"
            . 'In this package only: ' . implode(', ', array_diff($emitted, $contract)),
        );
    }

    /**
     * A static scan can never find *every* way a string reaches an `error` key,
     * and pretending otherwise is how a NEW code slips in unnoticed — the
     * dangerous direction, because a missed *rename* still shows up as "in the
     * fixture only" while a missed *addition* shows up as nothing at all.
     *
     * So rather than widen the pattern and hope, this counts every `error` key
     * in `src/` and insists the scanner could read all of them. Anything it
     * could not read is named by file and line and handed to a human, because
     * a test that says "I could not check this" beats one that silently checks
     * nothing.
     */
    public function test_no_error_key_in_this_package_escapes_the_scanner(): void
    {
        $scan = $this->scanSource();

        $unreadable = [];

        foreach ($scan['unresolved'] as $emission) {
            if (isset(self::DYNAMIC_EMISSIONS[$emission['expression']])) {
                continue;
            }

            $unreadable[] = "{$emission['file']}:{$emission['line']}  error => {$emission['expression']}";
        }

        $this->assertSame(
            [],
            $unreadable,
            "This package writes an `error` key whose value the contract scanner cannot read, so\n"
            . "test_the_contract_and_this_package_list_the_same_codes() is blind to whatever code it emits.\n\n"
            . implode("\n", $unreadable) . "\n\n"
            . "Check each one against packages/flutter-sdk/test/fixtures/proxy_contract.json BY HAND. If the\n"
            . 'code it produces is already in the fixture and already asserted elsewhere, record it in '
            . "ContractFixtureTest::DYNAMIC_EMISSIONS with the reason. Otherwise the Flutter package does not\n"
            . 'know this code exists.',
        );

        $this->assertSame(
            $scan['total'],
            count($scan['resolved']) + count($scan['unresolved']),
            'The scanner lost track of an `error` key: the number it classified does not equal the number it '
            . 'found. Fix the scanner before trusting either contract test.',
        );

        $this->assertGreaterThan(
            0,
            $scan['total'],
            'Scanned ' . self::SOURCE . ' and found no `error` key at all. Either the scan is broken or it is '
            . 'pointed at the wrong directory — set equality would then misreport a broken scanner as this '
            . 'package having stopped emitting every code it has.',
        );
    }

    /**
     * Every code this package can put in an `error` key: the string literals
     * written into JSON responses across `src/`, plus the `VerifyFailure`
     * values, which reach the wire as `$result->failure?->value` and so never
     * appear as a literal next to `'error'`.
     *
     * @return list<string>
     */
    private function codesEmittedBySource(): array
    {
        $scan = $this->scanSource();

        // Asserted on the SCAN's own results, before the enum values are mixed
        // in: seeded with those five, a broken scan is never empty, and the
        // set-equality failure would then read as "this package stopped
        // emitting eight codes" rather than "the scan is broken".
        $this->assertNotEmpty(
            $scan['resolved'],
            'Scanned ' . self::SOURCE . " and read no error code literal at all — the scan is broken.",
        );

        $enumValues = array_map(static fn (VerifyFailure $case): string => $case->value, VerifyFailure::cases());

        return array_values(array_unique(array_merge($enumValues, $scan['resolved'])));
    }

    /**
     * Finds every `'error' => …` / `"error" => …` in `src/` and splits them into
     * the ones whose value is a readable string literal and the ones that are
     * an expression. Both quote styles are accepted, and the literal itself may
     * contain anything — digits included — so a code like `otp_v2_unavailable`
     * is read rather than skipped.
     *
     * @return array{resolved: list<string>, unresolved: list<array{file: string, line: int, expression: string}>, total: int}
     *
     * `resolved` holds one entry per readable emission, duplicates included.
     */
    private function scanSource(): array
    {
        $resolved   = [];
        $unresolved = [];
        $total      = 0;

        /** @var iterable<SplFileInfo> $files */
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::SOURCE));

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $contents = (string) file_get_contents($file->getPathname());

            // The value is everything up to the element separator, so an
            // expression is captured whole rather than skipped.
            preg_match_all(
                '/([\'"])error\1\s*=>\s*([^,\]\)\r\n]*)/',
                $contents,
                $matches,
                PREG_OFFSET_CAPTURE | PREG_SET_ORDER,
            );

            foreach ($matches as $match) {
                $total++;

                $expression = trim($match[2][0]);
                $literal    = $this->readLiteral($expression);

                if ($literal !== null) {
                    $resolved[] = $literal;

                    continue;
                }

                $unresolved[] = [
                    // realpath so the human is handed src/Http/... rather than
                    // tests/../src/Http/..., and forward slashes so the line is
                    // clickable on either OS.
                    'file'       => str_replace('\\', '/', (string) realpath($file->getPathname())),
                    'line'       => substr_count(substr($contents, 0, (int) $match[0][1]), "\n") + 1,
                    'expression' => $expression,
                ];
            }
        }

        // Deliberately NOT deduplicated: the count is what the caller balances
        // against `total`, and `service_unavailable` is written three times.
        return [
            'resolved'   => $resolved,
            'unresolved' => $unresolved,
            'total'      => $total,
        ];
    }

    /**
     * A single- or double-quoted literal with no escape and no interpolation.
     * `"carrier_{$suffix}"` is rejected on the `$` — its value is decided at
     * runtime, so reading it as text would be a lie.
     */
    private function readLiteral(string $expression): ?string
    {
        if (preg_match('/^\'([^\'\\\\]*)\'$/', $expression, $m) === 1) {
            return $m[1];
        }

        if (preg_match('/^"([^"\\\\$]*)"$/', $expression, $m) === 1) {
            return $m[1];
        }

        return null;
    }
}
