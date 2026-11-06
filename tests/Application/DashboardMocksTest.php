<?php

declare(strict_types=1);

namespace Intakt\Tests\Application;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

// Because a mock that loads a missing asset or a screenshot job for a missing page breaks the design documents.
#[CoversNothing]
final class DashboardMocksTest extends TestCase
{
    private const string MOCKS = '/docs/design/mocks';

    public function testEveryAssetThePagesAndTheSharedScriptsLoadExists(): void
    {
        $mocks = dirname(__DIR__, 2).self::MOCKS;
        $sources = [...(array) glob($mocks.'/*.html'), ...(array) glob($mocks.'/assets/*.js')];
        $missing = [];

        foreach ($sources as $source) {
            preg_match_all('/(?:href|src)="((?:\.\.\/)*assets\/[^"]+)"/', (string) file_get_contents((string) $source), $matches);
            foreach ($matches[1] as $asset) {
                if (!is_file($mocks.'/'.$asset)) {
                    $missing[] = basename((string) $source).': '.$asset;
                }
            }
        }

        self::assertNotSame([], $sources);
        self::assertSame([], $missing);
    }

    public function testEveryPageTheScreenshotGeneratorOpensExists(): void
    {
        $mocks = dirname(__DIR__, 2).self::MOCKS;
        $script = (string) file_get_contents($mocks.'/tools/screenshots.js');

        self::assertSame(1, preg_match('/const PAGES = \{(.*?)\};/s', $script, $block));
        preg_match_all("/^\\s*'?([a-z-]+)'?:/m", $block[1] ?? '', $pages);
        $missing = array_values(array_filter($pages[1], static fn (string $page): bool => !is_file($mocks.'/'.$page.'.html')));

        self::assertNotSame([], $pages[1]);
        self::assertSame([], $missing);
    }
}
