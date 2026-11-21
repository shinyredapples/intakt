<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\RunReviewRound;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
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

    private const string OTHER_SPELLING = 'https://github.com/Acme/Widgets/pull/7';

    private const string ANOTHER_PULL = 'https://github.com/acme/widgets/pull/8';

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

    public function testARoundJudgedUnderAnotherSpellingOfTheAddressContinuesTheOneSequenceAsTheLatestJudgedRound(): void
    {
        $fixture = new RoundFixture(self::getContainer());
        $this->judgedRound($fixture);
        $fixture->clock->modify('+1 second');
        $this->judgedRound($fixture);
        $fixture->clock->modify('+1 second');
        $newer = $this->judgedRound($fixture, 'https://github.com/Acme/widgets/pull/7');

        self::assertSame([3, $newer], [$fixture->round($newer)->number(), $fixture->transactions()->latestJudgedAt('alpha', self::PULL, SampleIdentity::HEAD)?->id()->toRfc4122()]);
    }

    public function testTheHighestNumberedRoundOfTheOneSequenceIsTheLatestJudgedRoundThoughTheClockWentBackBeforeItsSpellingWasJudged(): void
    {
        $fixture = new RoundFixture(self::getContainer());
        $this->judgedRound($fixture);
        $second = $this->judgedRound($fixture);
        $this->roundLeftUnderAnOldSpelling($fixture);
        $fixture->clock->modify('-1 hour');
        $newest = $this->judgedRound($fixture, self::OTHER_SPELLING);
        $rounds = $fixture->transactions();

        self::assertSame(
            [$newest, true, false],
            [$rounds->latestJudgedAt('alpha', self::PULL, SampleIdentity::HEAD)?->id()->toRfc4122(), $rounds->judgedAfter($fixture->round($second)), $rounds->judgedAfter($fixture->round($newest))],
        );
    }

    public function testTheLatestJudgedRoundIsTheSameWhateverSpellingTheWatcherNamesThePullRequestWith(): void
    {
        $fixture = new RoundFixture(self::getContainer());
        $this->judgedRound($fixture);
        $this->roundLeftUnderAnOldSpelling($fixture);
        $fixture->clock->modify('-1 hour');
        $newest = $this->judgedRound($fixture);

        self::assertSame($newest, $fixture->transactions()->latestJudgedAt('alpha', self::OTHER_SPELLING, SampleIdentity::HEAD)?->id()->toRfc4122());
    }

    public function testAHeadNoRoundReviewedHasNoLatestJudgedRound(): void
    {
        $fixture = new RoundFixture(self::getContainer());
        $this->judgedRound($fixture);

        self::assertNull($fixture->transactions()->latestJudgedAt('alpha', self::PULL, str_repeat('0', 40)));
    }

    // Judged on another pull request and written under an old spelling of this one, because only a round an older install stored apart (migration Version20261122100000 left it so) forms a second sequence whose id can be newer than the rounds judged after the clock went back.
    private function roundLeftUnderAnOldSpelling(RoundFixture $fixture): void
    {
        if ($fixture->entities->getConnection()->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            self::markTestSkipped('MySQL and MariaDB compare the address without letter case, so an old spelling that differs only in letter case shares the row of the canonical one there.');
        }

        $this->judgedRound($fixture, self::ANOTHER_PULL);

        foreach (['change_findings', 'review_round'] as $table) {
            $fixture->entities->getConnection()->executeStatement(sprintf('UPDATE %s SET change_ref = ? WHERE change_ref = ?', $table), [self::OTHER_SPELLING, self::ANOTHER_PULL]);
        }
    }

    private function judgedRound(RoundFixture $fixture, string $address = self::PULL): string
    {
        $sent = count($fixture->bus->messages);
        $judged = count($fixture->judgings());
        $round = $fixture->startAtHead($address, SampleIdentity::HEAD);
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
