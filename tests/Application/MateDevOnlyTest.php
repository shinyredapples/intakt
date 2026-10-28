<?php

declare(strict_types=1);

namespace Intakt\Tests\Application;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;

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
        $files = new Finder()->files()->in(self::ROOT.'/src')->contains('Symfony\AI\Mate');
        foreach ($files as $file) {
            $matches[] = $file->getRelativePathname();
        }

        self::assertSame([], $matches);
    }

    /**
     * @return array{require: array<mixed>, require-dev: array<mixed>}
     */
    private function manifest(): array
    {
        $manifest = json_decode((string) file_get_contents(self::ROOT.'/composer.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($manifest);
        self::assertIsArray($manifest['require']);
        self::assertIsArray($manifest['require-dev']);

        return ['require' => $manifest['require'], 'require-dev' => $manifest['require-dev']];
    }
}
