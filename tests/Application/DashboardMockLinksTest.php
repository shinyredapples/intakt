<?php

declare(strict_types=1);

namespace Intakt\Tests\Application;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

// Because the mocks must open from a checkout alone, a page may link only files in the repository or an anchor.
#[CoversNothing]
final class DashboardMockLinksTest extends TestCase
{
    public function testNoMockPageLinksAnOutsideHost(): void
    {
        $pages = (array) glob(dirname(__DIR__, 2).'/docs/design/mocks/*.html');
        $outside = [];

        foreach ($pages as $page) {
            preg_match_all('/(?:href|src)="([^"]*)"/', (string) file_get_contents((string) $page), $matches);
            foreach ($matches[1] as $link) {
                if (1 === preg_match('#^(?:[a-z][a-z0-9+.-]*:|//)#i', $link)) {
                    $outside[] = basename((string) $page).': '.$link;
                }
            }
        }

        self::assertNotSame([], $pages);
        self::assertSame([], $outside);
    }
}
