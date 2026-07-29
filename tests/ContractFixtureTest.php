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
 * contract stays assertable even if the testbench harness breaks.
 */
class ContractFixtureTest extends BaseTestCase
{
    private const FIXTURE = __DIR__ . '/../../flutter-sdk/test/fixtures/proxy_contract.json';

    private const SOURCE = __DIR__ . '/../src';

    /** @return array<string, array{code: string, status: int, extra: ?string}> */
    private function contract(): array
    {
        $this->assertFileExists(self::FIXTURE, 'The shared contract fixture is missing.');

        $decoded = json_decode((string) file_get_contents(self::FIXTURE), true, flags: JSON_THROW_ON_ERROR);

        $byCode = [];

        foreach ($decoded['errors'] as $entry) {
            $byCode[$entry['code']] = $entry;
        }

        return $byCode;
    }

    /**
     * Every error the mapper can produce, as `[code, status]`. Both mapper
     * entry points are covered, and `forVerify` is driven from
     * `VerifyFailure::cases()` so a new enum case cannot be added without
     * this list growing with it.
     *
     * @return list<array{0: string, 1: int}>
     */
    private function everyMapperEmission(): array
    {
        $emissions = [];

        foreach ([
            new RateLimitException('too fast', 900),
            new InvalidRequestException('Validation failed.', ['to' => ['bad']]),
            new MyOtpWayException('anything else', 500),
        ] as $exception) {
            [$body, $status] = ProxyErrorMapper::forSend($exception);
            $emissions[] = [$body['error'], $status];
        }

        foreach (VerifyFailure::cases() as $failure) {
            [$body, $status] = ProxyErrorMapper::forVerify(new VerifyResult(verified: false, failure: $failure));
            $emissions[] = [$body['error'], $status];
        }

        // The `?? 'invalid_code'` arm: a failed verify the client could not
        // classify still has to speak the contract.
        [$body, $status] = ProxyErrorMapper::forVerify(new VerifyResult(verified: false));
        $emissions[] = [$body['error'], $status];

        return $emissions;
    }

    public function test_the_mapper_emits_only_codes_the_contract_lists(): void
    {
        $contract = $this->contract();

        foreach ($this->everyMapperEmission() as [$code, $status]) {
            $this->assertArrayHasKey(
                $code,
                $contract,
                "ProxyErrorMapper emits \"{$code}\" but the shared contract fixture does not list it. "
                . 'Add it to the fixture and to the Flutter package before shipping.',
            );
        }
    }

    public function test_the_statuses_match_the_contract(): void
    {
        $contract = $this->contract();

        foreach ($this->everyMapperEmission() as [$code, $status]) {
            $this->assertArrayHasKey($code, $contract, "ProxyErrorMapper emits an unlisted \"{$code}\".");

            $this->assertSame(
                $contract[$code]['status'],
                $status,
                "The contract says \"{$code}\" is HTTP {$contract[$code]['status']}, but the mapper returned {$status}.",
            );
        }
    }

    public function test_a_successful_verify_matches_the_contract(): void
    {
        $decoded = json_decode((string) file_get_contents(self::FIXTURE), true, flags: JSON_THROW_ON_ERROR);

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
     * Every code this package can put in an `error` key: the string literals
     * written into JSON responses across `src/`, plus the `VerifyFailure`
     * values, which reach the wire as `$result->failure?->value` and so never
     * appear as a literal next to `'error'`.
     *
     * @return list<string>
     */
    private function codesEmittedBySource(): array
    {
        $codes = array_map(static fn (VerifyFailure $case): string => $case->value, VerifyFailure::cases());

        /** @var iterable<SplFileInfo> $files */
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::SOURCE));

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            preg_match_all(
                "/'error'\s*=>\s*'([a-z_]+)'/",
                (string) file_get_contents($file->getPathname()),
                $matches,
            );

            $codes = array_merge($codes, $matches[1]);
        }

        $this->assertNotEmpty($codes, 'Scanned src/ and found no error codes at all — the scan is broken.');

        return array_values(array_unique($codes));
    }
}
