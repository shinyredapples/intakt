<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Infrastructure;

use Intakt\Core\Review\Infrastructure\StoredLatestRoundStatus;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewRounds;
use Intakt\Plugin\Forge\Contract\RoundStatusLabel;
use Intakt\Tests\Core\Review\SampleIdentity;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\RoundFixture;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\VerdictPerRole;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

#[Group('persistence')]
#[CoversClass(StoredLatestRoundStatus::class)]
#[CoversClass(ReviewRounds::class)]
final class StoredLatestRoundStatusTest extends KernelTestCase
{
    private const string PULL = 'https://github.com/acme/widgets/pull/7';

    public function testAChangeWithoutARoundHasNoStatus(): void
    {
        $fixture = new RoundFixture(self::getContainer());

        self::assertNull($this->statusOf($fixture, self::PULL));
    }

    public function testAQueuedRoundIsInReview(): void
    {
        $fixture = new RoundFixture(self::getContainer());
        $fixture->startAtHead(self::PULL, SampleIdentity::HEAD);

        self::assertSame(RoundStatusLabel::InReview, $this->statusOf($fixture, self::PULL));
    }

    public function testAJudgedRoundIsWaitingOnYou(): void
    {
        $fixture = new RoundFixture(self::getContainer());
        $this->judgedRound($fixture);

        self::assertSame(RoundStatusLabel::WaitingOnYou, $this->statusOf($fixture, self::PULL));
    }

    public function testARoundQueuedAfterAJudgedOneIsInReview(): void
    {
        $fixture = new RoundFixture(self::getContainer());
        $this->judgedRound($fixture);
        $fixture->startAtHead(self::PULL, SampleIdentity::HEAD);

        self::assertSame(RoundStatusLabel::InReview, $this->statusOf($fixture, self::PULL));
    }

    public function testTheStoredStateCountsThoughThisWorkerLoadedTheRoundBeforeItsJudging(): void
    {
        $fixture = new RoundFixture(self::getContainer());
        $loaded = $fixture->round($fixture->startAtHead(self::PULL, SampleIdentity::HEAD));
        $fixture->entities->getConnection()->executeStatement('UPDATE review_round SET state = ? WHERE id = ?', ['judged', $loaded->id()->toBinary()]);

        self::assertSame(RoundStatusLabel::WaitingOnYou, $this->statusOf($fixture, self::PULL));
    }

    public function testAnotherSpellingOfThePullRequestFindsTheSameRound(): void
    {
        $fixture = new RoundFixture(self::getContainer());
        $this->judgedRound($fixture);

        self::assertSame(RoundStatusLabel::WaitingOnYou, $this->statusOf($fixture, 'https://github.com/Acme/Widgets/pull/7'));
    }

    private function statusOf(RoundFixture $fixture, string $change): ?RoundStatusLabel
    {
        return new StoredLatestRoundStatus($fixture->transactions())->of('alpha', $change);
    }

    private function judgedRound(RoundFixture $fixture): void
    {
        $sent = count($fixture->bus->messages);
        $judged = count($fixture->judgings());
        $fixture->startAtHead(self::PULL, SampleIdentity::HEAD);
        $vendor = new VerdictPerRole([]);

        foreach (array_slice($fixture->bus->messages, $sent) as $message) {
            $fixture->deliver($message, $vendor);
        }

        foreach (array_slice($fixture->judgings(), $judged) as $judging) {
            $fixture->judge($vendor)($judging);
        }
    }
}
