<?php

declare(strict_types=1);

namespace Intakt\Tests\Application;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class RequiredExtensionsTest extends TestCase
{
    private const string ROOT = __DIR__.'/../..';

    // Compiled into every PHP 8 build, so no manifest entry can be missing for them.
    private const array ALWAYS_COMPILED = ['ext-date', 'ext-hash', 'ext-json', 'ext-pcre', 'ext-reflection', 'ext-spl', 'ext-standard'];

    public function testEveryExtensionALockedPackageRequiresIsDeclaredInTheManifest(): void
    {
        $lock = $this->decode('composer.lock');
        $needed = [];

        foreach ([...$this->list($lock, 'packages'), ...$this->list($lock, 'packages-dev')] as $package) {
            self::assertIsArray($package);

            foreach ($this->extensionKeys($package['require'] ?? []) as $requirement) {
                if (!in_array($requirement, self::ALWAYS_COMPILED, true)) {
                    $needed[$requirement][] = $package['name'];
                }
            }
        }

        $missing = array_diff_key($needed, array_flip($this->declaredExtensions()));

        self::assertSame([], $missing, 'composer.json must list every extension a locked package requires');
    }

    public function testTheLockPlatformBlockMirrorsTheDeclaredExtensions(): void
    {
        $platform = $this->extensionKeys($this->decode('composer.lock')['platform'] ?? null);

        self::assertSame($this->declaredExtensions(), $platform);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $file): array
    {
        $decoded = json_decode((string) file_get_contents(self::ROOT.'/'.$file), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        $byKey = [];

        foreach ($decoded as $key => $value) {
            $byKey[(string) $key] = $value;
        }

        return $byKey;
    }

    /**
     * @param array<string, mixed> $lock
     *
     * @return list<mixed>
     */
    private function list(array $lock, string $key): array
    {
        self::assertIsArray($lock[$key]);

        return array_values($lock[$key]);
    }

    /**
     * @return list<string>
     */
    private function extensionKeys(mixed $requirements): array
    {
        self::assertIsArray($requirements);
        $names = array_map(strval(...), array_keys($requirements));

        return array_values(array_filter($names, static fn (string $name): bool => str_starts_with($name, 'ext-')));
    }

    /**
     * @return list<string>
     */
    private function declaredExtensions(): array
    {
        return $this->extensionKeys($this->decode('composer.json')['require'] ?? null);
    }
}
