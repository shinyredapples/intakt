<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\RunReviewRound;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Intakt\Core\Review\Contract\ChangeAddress;
use Intakt\Core\Review\Domain\ReviewRound;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewRounds;
use Intakt\Core\Review\UseCase\RunReviewRound\StoredFindings;
use Intakt\Tests\Core\Review\SampleIdentity;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;

#[Group('persistence')]
#[CoversClass(StoredFindings::class)]
#[CoversClass(ReviewRounds::class)]
#[UsesClass(ChangeAddress::class)]
final class RoundsAcrossSpellingsTest extends KernelTestCase
{
    private const string PULL = 'https://github.com/acme/widgets/pull/7';
    private const string OTHER_SPELLING = 'https://github.com/Acme/Widgets/pull/7';

    public function testTwoSpellingsOfOnePullRequestNumberTheirRoundsOneAndTwo(): void
    {
        $fixture = new RoundFixture(self::getContainer());

        $first = $fixture->round($fixture->startAtHead(self::PULL, SampleIdentity::HEAD));
        $second = $fixture->round($fixture->startAtHead(self::OTHER_SPELLING, SampleIdentity::HEAD));

        self::assertSame([1, 2], [$first->number(), $second->number()]);
    }

    public function testARoundIsStoredUnderTheCanonicalAddressWhateverSpellingNamedIt(): void
    {
        $fixture = new RoundFixture(self::getContainer());

        $round = $fixture->round($fixture->startAtHead(self::OTHER_SPELLING, SampleIdentity::HEAD));

        self::assertSame(self::PULL, $round->change());
    }

    public function testALaterJudgedRoundOfTheSameHeadUnderAnotherSpellingSupersedesTheEarlierOne(): void
    {
        $fixture = new RoundFixture(self::getContainer());
        $earlier = $this->judgedRound($fixture, self::PULL);
        $this->judgedRound($fixture, self::OTHER_SPELLING);

        self::assertTrue($fixture->transactions()->judgedAfter($fixture->round($earlier)));
    }

    public function testTheRoundsOfAChangeAreOneSequenceAcrossSpellings(): void
    {
        $fixture = new RoundFixture(self::getContainer());
        $first = $fixture->startAtHead(self::OTHER_SPELLING, SampleIdentity::HEAD);
        $second = $fixture->startAtHead(self::PULL, SampleIdentity::HEAD);

        $rounds = $fixture->transactions()->ofChange($fixture->round($first));

        self::assertSame([$first, $second], array_map(static fn (ReviewRound $round): string => $round->id()->toRfc4122(), $rounds));
    }

    public function testTwoSpellingsOfOnePullRequestWithAFragmentNumberTheirRoundsOneAndTwo(): void
    {
        $fixture = new RoundFixture(self::getContainer());

        $first = $fixture->round($fixture->startAtHead(self::OTHER_SPELLING.'#discussion', SampleIdentity::HEAD));
        $second = $fixture->round($fixture->startAtHead(self::PULL.'#discussion', SampleIdentity::HEAD));

        self::assertSame([1, 2, self::PULL], [$first->number(), $second->number(), $second->change()]);
    }

    public function testTwoLocalBranchesWhoseNamesDifferOnlyInLetterCaseNumberTheirRoundsApart(): void
    {
        $fixture = new RoundFixture(self::getContainer());
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);

        if ($connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            self::markTestSkipped('MySQL and MariaDB compare the address without letter case, so two branch names that differ only in letter case share one row there.');
        }

        $upper = $fixture->round($fixture->startAtHead('feat/Widget', SampleIdentity::HEAD));
        $lower = $fixture->round($fixture->startAtHead('feat/widget', SampleIdentity::HEAD));

        self::assertSame(['feat/Widget', 1, 1], [$upper->change(), $upper->number(), $lower->number()]);
    }

    private function judgedRound(RoundFixture $fixture, string $address): string
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
