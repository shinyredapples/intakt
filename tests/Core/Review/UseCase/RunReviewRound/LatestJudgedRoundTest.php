<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\RunReviewRound;

use Intakt\Core\Review\UseCase\RunReviewRound\ReviewRounds;
use Intakt\Tests\Core\Review\SampleIdentity;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

#[Group('persistence')]
#[CoversClass(ReviewRounds::class)]
final class LatestJudgedRoundTest extends KernelTestCase
{
    private const string PULL = 'https://github.com/acme/widgets/pull/7';

    public function testTheLatestJudgedRoundOfAHeadIsTheOneNoLaterRoundSupersedesThoughTheClockWentBackBetweenThem(): void
    {
        $fixture = new RoundFixture(self::getContainer());
        $this->judgedRound($fixture);
        $fixture->clock->modify('-1 second');
        $newer = $this->judgedRound($fixture);

        self::assertSame($newer, $fixture->transactions()->latestJudgedAt('alpha', self::PULL, SampleIdentity::HEAD)?->id()->toRfc4122());
    }

    public function testTheLatestJudgedRoundOfAHeadIsTheNewerOneWhenTheClockRunsForward(): void
    {
        $fixture = new RoundFixture(self::getContainer());
        $this->judgedRound($fixture);
        $fixture->clock->modify('+1 second');
        $newer = $this->judgedRound($fixture);

        self::assertSame($newer, $fixture->transactions()->latestJudgedAt('alpha', self::PULL, SampleIdentity::HEAD)?->id()->toRfc4122());
    }

    private function judgedRound(RoundFixture $fixture): string
    {
        $sent = count($fixture->bus->messages);
        $judged = count($fixture->judgings());
        $round = $fixture->startAtHead(self::PULL, SampleIdentity::HEAD);
        $vendor = new VerdictPerRole([]);

        foreach (array_slice($fixture->bus->messages, $sent) as $message) {
            $fixture->deliver($message, $vendor);
        }

        foreach (array_slice($fixture->judgings(), $judged) as $judging) {
            $fixture->judge($vendor)($judging);
        }

        return $round;
    }
}
