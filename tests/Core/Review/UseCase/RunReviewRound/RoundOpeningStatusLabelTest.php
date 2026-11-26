<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\RunReviewRound;

use Doctrine\ORM\EntityManagerInterface;
use Intakt\Core\Review\Infrastructure\StoredLatestRoundStatus;
use Intakt\Core\Review\UseCase\RunReviewRound\MirrorOpenedRoundStatusHandler;
use Intakt\Core\Review\UseCase\RunReviewRound\RoundOpening;
use Intakt\Plugin\Forge\Contract\RoundStatusLabel;
use Intakt\Plugin\Forge\Contract\RoundStatusMirror;
use Intakt\Tests\Plugin\Forge\RecordingStatusLabels;
use Intakt\Tests\Support\KernelTestCase;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Psr\Log\NullLogger;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

#[CoversClass(MirrorOpenedRoundStatusHandler::class)]
#[CoversClass(RoundOpening::class)]
#[UsesClass(RoundStatusMirror::class)]
#[UsesClass(RoundStatusLabel::class)]
#[UsesClass(StoredLatestRoundStatus::class)]
final class RoundOpeningStatusLabelTest extends KernelTestCase
{
    private RoundFixture $fixture;

    private RecordingStatusLabels $labels;

    public function testQueueingARoundReconcilesThePullRequestsStatusLabelToInReviewOnceItsMessageIsHandled(): void
    {
        $this->fixture->startHandlerOn($this->fixture->bus)($this->fixture->commandFor(RoundFixture::CHANGE, 2));

        $this->handleAnnounced();

        self::assertSame(['[review:full] -> [review:full, status:in-review]'], $this->labels->passes);
    }

    public function testARoundOpenedInsideTheWatchersTransactionWritesNoLabelBeforeThatTransactionCommits(): void
    {
        $connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $connection->beginTransaction();
        $this->fixture->startHandlerOn($this->fixture->bus)($this->fixture->commandFor(RoundFixture::CHANGE, 2));
        $insideTheTransaction = $this->labels->passes;
        $connection->commit();

        $this->handleAnnounced();

        self::assertSame([[], ['[review:full] -> [review:full, status:in-review]']], [$insideTheTransaction, $this->labels->passes]);
    }

    public function testARoundTheWatchersTransactionRolledBackLeavesNoStatusLabel(): void
    {
        $connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $connection->beginTransaction();

        try {
            $this->fixture->startHandlerOn($this->fixture->bus)($this->fixture->commandFor(RoundFixture::CHANGE, 2));
        } finally {
            $connection->rollBack();
        }

        self::assertSame([[], null], [$this->labels->passes, new StoredLatestRoundStatus($this->fixture->transactions())->of('alpha', RoundFixture::CHANGE)]);
    }

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture = new RoundFixture(self::getContainer());
        $this->labels = new RecordingStatusLabels(['review:full']);
    }

    private function handleAnnounced(): void
    {
        $mirror = new RoundStatusMirror(new StoredLatestRoundStatus($this->fixture->transactions()), new LockFactory(new InMemoryStore()), new NullLogger(), $this->labels);
        $handler = new MirrorOpenedRoundStatusHandler($this->fixture->transactions(), $mirror);

        foreach ($this->fixture->bus->announced as $opened) {
            $handler($opened);
        }
    }
}
