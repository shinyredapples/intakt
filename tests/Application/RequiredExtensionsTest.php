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

        foreach ([...$lock['packages'], ...$lock['packages-dev']] as $package) {
            self::assertIsArray($package);

            foreach (array_keys($package['require'] ?? []) as $requirement) {
                if (str_starts_with((string) $requirement, 'ext-') && !in_array($requirement, self::ALWAYS_COMPILED, true)) {
                    $needed[$requirement][] = $package['name'];
                }
            }
        }

        $missing = array_diff_key($needed, array_flip($this->declaredExtensions()));

        self::assertSame([], $missing, 'composer.json must list every extension a locked package requires');
    }

    public function testTheLockPlatformBlockMirrorsTheDeclaredExtensions(): void
    {
        $platform = array_filter(array_keys($this->decode('composer.lock')['platform']), static fn (string $name): bool => str_starts_with($name, 'ext-'));

        self::assertSame($this->declaredExtensions(), array_values($platform));
    }

    /**
     * @return list<string>
     */
    private function declaredExtensions(): array
    {
        $required = array_keys($this->decode('composer.json')['require']);

        return array_values(array_filter($required, static fn (string $name): bool => str_starts_with($name, 'ext-')));
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $file): array
    {
        $decoded = json_decode((string) file_get_contents(self::ROOT.'/'.$file), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
