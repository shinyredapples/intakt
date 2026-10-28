<?php

declare(strict_types=1);

namespace Intakt\Tests\Application;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class MateDevOnlyTest extends TestCase
{
    private const string ROOT = __DIR__.'/../..';

    public function testMateIsARequireDevDependencyOnly(): void
    {
        $manifest = $this->manifest();

        self::assertArrayHasKey('symfony/ai-mate', $manifest['require-dev']);
        self::assertArrayNotHasKey('symfony/ai-mate', $manifest['require']);
        self::assertArrayNotHasKey('symfony/ai-symfony-mate-extension', $manifest['require']);
    }

    public function testNoApplicationCodeReferencesMate(): void
    {
        $matches = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::ROOT.'/src', \FilesystemIterator::SKIP_DOTS)) as $file) {
            if (str_contains((string) file_get_contents($file->getPathname()), 'Symfony\\AI\\Mate')) {
                $matches[] = $file->getPathname();
            }
        }

        self::assertSame([], $matches);
    }

    /**
     * @return array{require: array<string, string>, require-dev: array<string, string>}
     */
    private function manifest(): array
    {
        /** @var array{require: array<string, string>, require-dev: array<string, string>} */
        return json_decode((string) file_get_contents(self::ROOT.'/composer.json'), true, flags: \JSON_THROW_ON_ERROR);
    }
}
