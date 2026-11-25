<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Watcher\UseCase\WatchPullRequests;

use DateTimeImmutable;
use Intakt\Core\Review\Contract\ChangeSource;
use Intakt\Core\Review\Contract\Labels;
use Intakt\Core\Review\Contract\RequestedRound;
use Intakt\Core\Review\Contract\StartReview;
use Intakt\Core\Review\Contract\StartReviewAtHead;
use Intakt\Core\Watcher\UseCase\WatchPullRequests\WatchedProject;
use Intakt\Plugin\Forge\Contract\ChangeId;
use Intakt\Plugin\Forge\Contract\CommitId;
use Intakt\Plugin\Forge\Contract\OpenChange;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(WatchedProject::class)]
#[UsesClass(StartReview::class)]
#[UsesClass(StartReviewAtHead::class)]
#[UsesClass(RequestedRound::class)]
#[UsesClass(ChangeSource::class)]
#[UsesClass(Labels::class)]
#[UsesClass(OpenChange::class)]
#[UsesClass(ChangeId::class)]
#[UsesClass(CommitId::class)]
final class WatchedProjectTest extends TestCase
{
    public function testTheRoundStartsWithTheSteeringLabelsItWasGiven(): void
    {
        $source = new ChangeSource('repo', 'main');
        $open = new OpenChange(new ChangeId('7'), new CommitId(str_repeat('a', 40)), new DateTimeImmutable('2026-11-02T09:00:00Z'));

        $start = new WatchedProject('alpha', $source, 'Review it.')->startAt($open, new Labels('review:full'));

        self::assertTrue($start->start->labels->contains('review:full'));
    }

    public function testARoundWithoutLabelsStartsWithNone(): void
    {
        $open = new OpenChange(new ChangeId('7'), new CommitId(str_repeat('a', 40)), new DateTimeImmutable('2026-11-02T09:00:00Z'));

        $start = new WatchedProject('alpha', new ChangeSource('repo', 'main'), 'Review it.')->startAt($open);

        self::assertFalse($start->start->labels->contains('review:full'));
    }
}
