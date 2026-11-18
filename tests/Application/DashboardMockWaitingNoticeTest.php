<?php

declare(strict_types=1);

namespace Intakt\Tests\Application;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

// Because an owner acts on the notice alone, the file it names must be the file its explanation names.
#[CoversNothing]
final class DashboardMockWaitingNoticeTest extends TestCase
{
    private const string WAITING = __DIR__.'/../../docs/design/mocks/waiting.html';

    public function testEachWaitingNoticeNamesTheSameFileAsItsExplanation(): void
    {
        preg_match_all('/<article class="wait-item"[^>]*id="([^"]+)">(.*?)<\/article>/s', (string) file_get_contents(self::WAITING), $items, PREG_SET_ORDER);
        $checked = [];
        $mismatches = [];

        foreach ($items as [, $id, $item]) {
            if (1 !== preg_match('/<h3>.*?\(([^()]+\.php)\)<\/h3>/s', $item, $title)) {
                continue;
            }
            preg_match('/<details[^>]*>.*?<code>([^<]+\.php)<\/code>/s', $item, $details);
            $checked[] = $id;
            if ($title[1] !== basename($details[1] ?? '')) {
                $mismatches[] = $id.': '.$title[1].' vs '.($details[1] ?? 'none');
            }
        }

        self::assertNotSame([], $checked);
        self::assertSame([], $mismatches);
    }
}
