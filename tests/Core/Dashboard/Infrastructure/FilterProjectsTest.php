<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Infrastructure;

use DateTimeImmutable;
use Intakt\Core\Ai\Contract\ProjectNames;
use Intakt\Core\Ai\Contract\TierResolver;
use Intakt\Core\Dashboard\Domain\ProjectOrder;
use Intakt\Core\Dashboard\Domain\ProjectSummary;
use Intakt\Core\Dashboard\Domain\ReviewSummary;
use Intakt\Core\Dashboard\Domain\RoundStart;
use Intakt\Core\Dashboard\Infrastructure\ConfiguredProjects;
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
#[CoversClass(ProjectOrder::class)]
#[CoversClass(ProjectSummary::class)]
#[CoversClass(ConfiguredProjects::class)]
#[UsesClass(LedgerReviews::class)]
#[UsesClass(LedgerReviewPages::class)]
#[UsesClass(LedgerRounds::class)]
#[UsesClass(RoundPayloads::class)]
#[UsesClass(LedgerPayloads::class)]
#[UsesClass(ReviewSummary::class)]
#[UsesClass(RoundStart::class)]
#[UsesClass(RecordedEvent::class)]
#[UsesClass(RecordedEvents::class)]
#[UsesClass(LedgerEvent::class)]
final class FilterProjectsTest extends TestCase
{
    public function testAReviewedAndABuiltProjectThatDifferOnlyInCaseComeInByteOrderWhicheverWasListedFirst(): void
    {
        self::assertSame(['Zeta', 'zeta'], $this->projectsOf(['zeta'], ['Zeta'])->names());
        self::assertSame(['Zeta', 'zeta'], $this->projectsOf(['Zeta'], ['zeta'])->names());
    }

    public function testAConfiguredProjectIsListedBeforeItsFirstReview(): void
    {
        self::assertSame(['alpha', 'idle', 'watched', 'zeta'], $this->projectsOf(['zeta'], ['alpha'], ['idle'])->names());
    }

    public function testAPageProjectThatDiffersFromASharedOneOnlyInCaseComesInByteOrder(): void
    {
        self::assertSame(['Zeta', 'zeta'], $this->projectsOf(['zeta'])->namesWith(['Zeta']));
    }

    public function testAPageProjectWithALeadingZeroComesBeforeTheSameNumberWithout(): void
    {
        self::assertSame(['alpha', 'zeta-02', 'zeta-2'], $this->projectsOf(['zeta-2', 'alpha'])->namesWith(['zeta-02']));
    }

    /**
     * @param list<string> $reviewed
     * @param list<string> $built
     * @param list<string> $configured
     */
    private function projectsOf(array $reviewed, array $built = [], array $configured = []): FilterProjects
    {
        $events = [];

        foreach ($reviewed as $index => $project) {
            $payload = json_encode(['round' => 'r'.($index + 1), 'project' => $project, 'reviewers' => 1], JSON_THROW_ON_ERROR);
            $events[] = new RecordedEvent(Uuid::v7(), new LedgerEvent(LedgerEventKind::ReviewStarted, $payload), new DateTimeImmutable('2026-11-10T12:00:00+00:00'));
        }

        foreach ($built as $project) {
            $payload = json_encode(['project' => $project, 'task' => '7', 'step' => 'task', 'outcome' => 'passed', 'failure' => null], JSON_THROW_ON_ERROR);
            $events[] = new RecordedEvent(Uuid::v7(), new LedgerEvent(LedgerEventKind::BuildStepEnded, $payload), new DateTimeImmutable('2026-11-10T12:00:00+00:00'));
        }

        $ledger = self::createStub(Ledger::class);
        $ledger->method('readInOrder')->willReturn(new RecordedEvents($events));

        return new FilterProjects(ListedReviews::on($ledger), $this->configured($configured));
    }

    /**
     * @param list<string> $names
     */
    private function configured(array $names): ConfiguredProjects
    {
        $tiers = self::createStub(TierResolver::class);
        $tiers->method('projects')->willReturn(new ProjectNames(...$names));

        return new ConfiguredProjects($tiers, [] === $names ? [] : ['watched' => []]);
    }
}
