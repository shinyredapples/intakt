<?php

declare(strict_types=1);

namespace Intakt\Tests\Application;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

// Because a switch announces its own state, a name that also carries a state contradicts it once the switch is off.
#[CoversNothing]
final class DashboardMockHealthSwitchTest extends TestCase
{
    private const string HEALTH = __DIR__.'/../../docs/design/mocks/health.html';

    public function testEachBackgroundJobSwitchIsNamedByItsJobAlone(): void
    {
        preg_match_all('~<article class="hrow[^>]*>.*?</article>~s', (string) file_get_contents(self::HEALTH), $rows);
        $names = [];

        foreach ($rows[0] as $row) {
            if (1 !== preg_match('~<input type="checkbox" role="switch"[^>]*aria-label="([^"]+)"~', $row, $switch)) {
                continue;
            }
            preg_match('~<h3>([^<]+)~', $row, $heading);
            $names[$switch[1]] = trim($heading[1] ?? '');
        }

        self::assertCount(6, $names);
        self::assertSame(array_keys($names), array_values($names));
    }

    public function testEachBackgroundJobRowCarriesTheNextRunItsSwitchRestores(): void
    {
        preg_match_all('~<article class="hrow[^>]*>.*?</article>~s', (string) file_get_contents(self::HEALTH), $rows);
        $switched = array_filter($rows[0], static fn (string $row): bool => str_contains($row, 'role="switch"'));
        $withNextRun = array_filter($switched, static fn (string $row): bool => 1 === preg_match('~<dt>Next run</dt><dd data-next="[^"]+">~', $row));

        self::assertCount(6, $switched);
        self::assertSame($switched, $withNextRun);
    }
}
