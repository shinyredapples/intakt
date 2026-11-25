<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\RunReviewRound;

use Intakt\Core\Review\Infrastructure\StoredLatestRoundStatus;
use Intakt\Core\Review\UseCase\RunReviewRound\RoundOpening;
use Intakt\Plugin\Forge\Contract\RoundStatusLabel;
use Intakt\Plugin\Forge\Contract\RoundStatusMirror;
use Intakt\Tests\Plugin\Forge\RecordingStatusLabels;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Psr\Log\NullLogger;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

#[CoversClass(RoundOpening::class)]
#[UsesClass(RoundStatusMirror::class)]
#[UsesClass(RoundStatusLabel::class)]
#[UsesClass(StoredLatestRoundStatus::class)]
final class RoundOpeningStatusLabelTest extends KernelTestCase
{
    public function testQueueingARoundReconcilesThePullRequestsStatusLabelToInReview(): void
    {
        $fixture = new RoundFixture(self::getContainer());
        $labels = new RecordingStatusLabels(['review:full']);
        $mirror = new RoundStatusMirror(new StoredLatestRoundStatus($fixture->transactions()), new LockFactory(new InMemoryStore()), new NullLogger(), $labels);

        $fixture->startHandlerOn($fixture->bus, $mirror)($fixture->commandFor(RoundFixture::CHANGE, 2));

        self::assertSame(['[review:full] -> [review:full, status:in-review]'], $labels->passes);
    }
}
