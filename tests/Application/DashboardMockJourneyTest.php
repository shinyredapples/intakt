<?php

declare(strict_types=1);

namespace Intakt\Tests\Application;

use Dom\HTMLDocument;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

// Because the Journey page is the design's proof that every figure is generated from one record, its mock may show no figure the others contradict, and no limit outside the owner area.
#[CoversNothing]
final class DashboardMockJourneyTest extends TestCase
{
    private const string MOCKS = __DIR__.'/../../docs/design/mocks/';

    public function testTheJourneyPageShowsNoUsageAgainstALimit(): void
    {
        $journey = (string) file_get_contents(self::MOCKS.'journey.html');

        self::assertStringNotContainsStringIgnoringCase('plan limit', $journey);
        self::assertStringNotContainsString('class="window"', $journey);
    }

    public function testUsageAgainstLimitsPerProjectShowsInTheOwnerAreaOfTeamAndAccounts(): void
    {
        $usage = $this->page('team.html')->getElementById('usage-by-project');

        self::assertNotNull($usage);
        self::assertStringContainsString('acme-shop', (string) $usage->textContent);
        self::assertStringContainsString('plan limit', (string) $usage->textContent);
    }

    public function testEachDaysTokensPerMergeAreThatDaysLaneTokensOverItsMerges(): void
    {
        $merges = $this->series('j-merges');
        $lanes = array_map($this->series(...), ['t-cb', 't-cr', 't-gb', 't-gr']);
        $expected = [];
        foreach ($merges as $day => $count) {
            $expected[] = (int) round(array_sum(array_column($lanes, $day)) / $count);
        }

        self::assertSame($expected, $this->series('j-permerge'));
    }

    public function testTokensByAccountAddUpToTokensByLane(): void
    {
        $lanes = array_sum(array_map(fn (string $id): int => array_sum($this->series($id)), ['t-cb', 't-cr', 't-gb', 't-gr']));
        $accounts = 0;
        foreach ($this->page('journey.html')->querySelectorAll('#accounts tbody td:last-child') as $cell) {
            $accounts += (int) round((float) $cell->textContent * 1000);
        }

        self::assertSame($lanes, $accounts);
    }

    public function testTheBacklogMovesEachDayByTheIssuesCreatedLessTheIssuesClosed(): void
    {
        $open = $this->series('j-open');
        $created = $this->series('j-created');
        $closed = $this->series('j-closed');
        $expected = [$open[0]];
        for ($day = 1; $day < \count($open); ++$day) {
            $expected[] = $expected[$day - 1] + $created[$day] - $closed[$day];
        }

        self::assertSame($expected, $open);
    }

    public function testEveryCitationOfAMilestoneEntryLeadsToTheSourceItNames(): void
    {
        $page = $this->page('journey.html');
        $wrong = [];
        $links = $page->querySelectorAll('#entries .ev-body a, #entries .ev-head a');
        foreach ($links as $link) {
            preg_match('/#(\d+)/', (string) $link->textContent, $cited);
            $target = $page->getElementById(substr((string) $link->getAttribute('href'), 1));
            if (null === $target || !str_contains((string) $target->textContent, '#'.($cited[1] ?? 'none').' ')) {
                $wrong[] = $link->textContent.' -> '.$link->getAttribute('href');
            }
        }

        self::assertGreaterThan(0, $links->length);
        self::assertSame([], $wrong);
    }

    public function testEveryClosedMilestoneHasAnEntry(): void
    {
        $page = $this->page('journey.html');
        $closed = [];
        foreach ($page->querySelectorAll('#milestones tbody tr') as $row) {
            $cells = $row->querySelectorAll('td');
            if ('open' !== $cells->item(2)?->textContent) {
                $closed[] = $cells->item(0)?->textContent.' closed';
            }
        }
        $entries = array_map(static fn ($heading): string => (string) $heading->textContent, iterator_to_array($page->querySelectorAll('#entries .ev-head h3')));

        self::assertNotSame([], $closed);
        self::assertEqualsCanonicalizing($closed, $entries);
    }

    /**
     * @return list<int>
     */
    private function series(string $id): array
    {
        $html = (string) file_get_contents(self::MOCKS.'journey.html');
        self::assertSame(1, preg_match('/C\(\$\("'.preg_quote($id, '/').'"\).*?values: (\[[^\]]+\])/', $html, $matches), 'Missing series '.$id);

        return json_decode($matches[1], true, flags: \JSON_THROW_ON_ERROR);
    }

    private function page(string $name): HTMLDocument
    {
        return HTMLDocument::createFromString((string) file_get_contents(self::MOCKS.$name), \LIBXML_NOERROR);
    }
}
