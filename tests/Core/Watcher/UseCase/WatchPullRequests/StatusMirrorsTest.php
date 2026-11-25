<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Watcher\UseCase\WatchPullRequests;

use Intakt\Core\Review\Contract\LatestRoundStatus;
use Intakt\Core\Review\Contract\ReviewStatus;
use Intakt\Core\Review\Infrastructure\StoredLatestRoundStatus;
use Intakt\Core\Watcher\UseCase\FindChangedPullRequests\FindChangedPullRequests;
use Intakt\Core\Watcher\UseCase\FindChangedPullRequests\SeenListings;
use Intakt\Core\Watcher\UseCase\WatchPullRequests\StatusMirrors;
use Intakt\Plugin\Forge\Contract\RoundStatusMirror;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\RoundFixture;
use Intakt\Tests\Core\Watcher\UseCase\FindChangedPullRequests\RecordedForge;
use Intakt\Tests\Plugin\Forge\RecordingStatusLabels;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;
use RuntimeException;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

#[CoversClass(StatusMirrors::class)]
#[UsesClass(RoundStatusMirror::class)]
#[UsesClass(StoredLatestRoundStatus::class)]
#[Group('persistence')]
final class StatusMirrorsTest extends KernelTestCase
{
    private const string FIRST = 'https://github.com/acme/widgets/pull/7';
    private const string SECOND = 'https://github.com/acme/widgets/pull/8';

    private RoundFixture $fixture;
    private LockFactory $locks;
    private FindChangedPullRequests $listing;
    private RecordingStatusLabels $labels;

    public function testAnUnchangedListingRepairsTheLabelOfARoundQueuedThroughAReviewTheWatcherDidNotStart(): void
    {
        $this->fixture->startFor(self::FIRST, $this->fixture->bus, 2);
        self::assertSame([], ($this->listing)('alpha'));

        $this->mirrorsOver(new StoredLatestRoundStatus($this->fixture->transactions()))->reconcileOpenOf('alpha');

        self::assertSame(['status:in-review'], $this->labels->labels);
    }

    public function testAnOpenPullRequestWithoutAStoredRoundIsLeftAlone(): void
    {
        $this->mirrorsOver(new StoredLatestRoundStatus($this->fixture->transactions()))->reconcileOpenOf('alpha');

        self::assertSame([], $this->labels->passes);
    }

    public function testARoundThatCannotBeReadIsLoggedAndTheNextPullRequestIsStillReconciled(): void
    {
        $rounds = self::createStub(LatestRoundStatus::class);
        $rounds->method('of')->willReturnCallback(static fn (string $project, string $change): ReviewStatus => self::FIRST === $change ? throw new RuntimeException('The database is gone.') : ReviewStatus::InReview);

        $this->mirrorsOver($rounds)->reconcileOpenOf('alpha');

        self::assertSame(['[] -> [status:in-review]'], $this->labels->passes);
        self::assertContains(['change' => self::FIRST, 'project' => 'alpha', 'reason' => 'The database is gone.'], array_column($this->fixture->log->cleanLogs(), 2));
    }

    protected function setUp(): void
    {
        $this->fixture = new RoundFixture(self::getContainer());
        $this->locks = new LockFactory(new InMemoryStore());
        $forge = new RecordedForge();
        $forge->opens('alpha', RecordedForge::change(self::FIRST, 'a', '2026-11-02T09:00:00Z'), RecordedForge::change(self::SECOND, 'b', '2026-11-02T09:00:00Z'));
        $this->listing = new FindChangedPullRequests($forge, new SeenListings($this->fixture->entities, $this->locks), $this->fixture->log);
        self::assertCount(2, ($this->listing)('alpha'));
        $this->labels = new RecordingStatusLabels([]);
    }

    private function mirrorsOver(LatestRoundStatus $rounds): StatusMirrors
    {
        return new StatusMirrors($this->listing, $rounds, new RoundStatusMirror($rounds, $this->locks, $this->fixture->log, $this->labels), $this->fixture->log);
    }
}
