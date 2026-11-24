<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Infrastructure;

use DateTimeImmutable;
use Intakt\Core\Dashboard\Domain\ProjectSummary;
use Intakt\Core\Dashboard\Domain\ReviewSummary;
use Intakt\Core\Dashboard\Domain\RoundStart;
use Intakt\Core\Dashboard\Infrastructure\FilterProjects;
use Intakt\Core\Dashboard\Infrastructure\LedgerReviewPages;
use Intakt\Core\Dashboard\Infrastructure\LedgerReviews;
use Intakt\Core\Dashboard\Infrastructure\LedgerRounds;
use Intakt\Core\Dashboard\Infrastructure\RoundPayloads;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\LedgerPayloads;
use Intakt\Core\Ledger\Contract\RecordedEvent;
use Intakt\Core\Ledger\Contract\RecordedEvents;
use Intakt\Tests\Support\ListedReviews;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(FilterProjects::class)]
#[UsesClass(LedgerReviews::class)]
#[UsesClass(LedgerReviewPages::class)]
#[UsesClass(LedgerRounds::class)]
#[UsesClass(RoundPayloads::class)]
#[UsesClass(LedgerPayloads::class)]
#[UsesClass(ProjectSummary::class)]
#[UsesClass(ReviewSummary::class)]
#[UsesClass(RoundStart::class)]
#[UsesClass(RecordedEvent::class)]
#[UsesClass(RecordedEvents::class)]
#[UsesClass(LedgerEvent::class)]
final class FilterProjectsTest extends TestCase
{
    public function testNamesThatDifferOnlyInCaseComeInByteOrderWhateverOrderTheLedgerNamesThem(): void
    {
        self::assertSame(
            [['Zeta', 'zeta'], ['Zeta', 'zeta']],
            [$this->projectsOf('zeta', 'Zeta')->names(), $this->projectsOf('Zeta', 'zeta')->names()],
        );
    }

    public function testAPageProjectWithALeadingZeroComesBeforeTheSameNumberWithout(): void
    {
        self::assertSame(['alpha', 'zeta-02', 'zeta-2'], $this->projectsOf('zeta-2', 'alpha')->namesWith(['zeta-02']));
    }

    private function projectsOf(string ...$projects): FilterProjects
    {
        $events = [];

        foreach (array_values($projects) as $index => $project) {
            $payload = json_encode(['round' => 'r'.($index + 1), 'project' => $project, 'reviewers' => 1], JSON_THROW_ON_ERROR);
            $events[] = new RecordedEvent(Uuid::v7(), new LedgerEvent(LedgerEventKind::ReviewStarted, $payload), new DateTimeImmutable('2026-11-10T12:00:00+00:00'));
        }

        $ledger = self::createStub(Ledger::class);
        $ledger->method('readInOrder')->willReturn(new RecordedEvents($events));

        return new FilterProjects(ListedReviews::on($ledger));
    }
}
