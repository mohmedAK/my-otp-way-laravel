<?php

declare(strict_types=1);

namespace MyOtpWay\Laravel\Tests;

/**
 * Reads the one file both packages assert against.
 *
 * Two test classes need it — `ContractFixtureTest` (which can drive the mapper
 * directly) and `ProxyRoutesTest` (which is the only place a real controller
 * response exists) — and the point of the fixture is that there is exactly one
 * copy of the contract. A second hand-written path, or a second hand-written
 * key name, would be a second copy.
 *
 * Nothing here touches the container, so the trait works in a plain PHPUnit
 * TestCase and in an Orchestra one alike.
 */
trait SharedContract
{
    /**
     * `__DIR__` is this trait's own directory (`tests/`), not the using class's,
     * so the path stays correct wherever the trait is mixed in.
     */
    private function contractFixturePath(): string
    {
        return __DIR__ . '/../../flutter-sdk/test/fixtures/proxy_contract.json';
    }

    /** @return array<string, array{code: string, status: int, extra: ?string}> */
    private function contract(): array
    {
        $path = $this->contractFixturePath();

        $this->assertFileExists($path, 'The shared contract fixture is missing.');

        $decoded = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        $byCode = [];

        foreach ($decoded['errors'] ?? [] as $entry) {
            $byCode[$entry['code']] = $entry;
        }

        $this->assertNotEmpty(
            $byCode,
            "The shared contract fixture at {$path} lists no error codes. Every test that reads it "
            . 'would pass while asserting nothing.',
        );

        return $byCode;
    }

    /** @return array{code: string, status: int, extra: ?string} */
    private function contractEntry(string $code): array
    {
        $contract = $this->contract();

        $this->assertArrayHasKey(
            $code,
            $contract,
            "This package emits \"{$code}\" but the shared contract fixture does not list it. "
            . 'Add it to the fixture and to the Flutter package before shipping.',
        );

        return $contract[$code];
    }
}
