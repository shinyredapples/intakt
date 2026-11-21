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
final class HandedOverRoundLookupTest extends KernelTestCase
{
    private const string PULL = 'https://github.com/acme/widgets/pull/7';
    private const string UPPER = 'https://github.com/Acme/Widgets/pull/7';

    public function testTheCanonicalLookupKeepsTheJudgedVerdictForTheRequestedHead(): void
    {
        $fixture = new RoundFixture(self::getContainer());
        $first = $this->judgeRound($fixture, self::PULL, SampleIdentity::HEAD);
        $fixture->clock->modify('-1 hour');
        $second = $this->judgeRound($fixture, self::UPPER, SampleIdentity::HEAD);
        $this->judgeRound($fixture, self::PULL, SampleIdentity::NEW_HEAD);

        $rounds = $fixture->transactions();
        self::assertSame($second, $rounds->latestJudgedAt('alpha', self::UPPER.'/?q=1#review', SampleIdentity::HEAD)?->id()->toRfc4122());
        self::assertTrue($rounds->judgedAfter($fixture->round($first)));
        self::assertFalse($rounds->judgedAfter($fixture->round($second)));
        self::assertNull($rounds->latestJudgedAt('other', self::UPPER, SampleIdentity::HEAD));
    }

    public function testACollidingLegacySpellingKeepsItsVerdictWhenItAloneReviewedTheListedHead(): void
    {
        $fixture = new RoundFixture(self::getContainer());
        $this->skipWhereTheAddressIgnoresLetterCase($fixture);
        $otherPull = 'https://github.com/acme/widgets/pull/8';
        $legacy = $this->judgeRound($fixture, $otherPull, SampleIdentity::HEAD);
        foreach (['change_findings', 'review_round'] as $table) {
            $fixture->entities->getConnection()->executeStatement("UPDATE $table SET change_ref = ? WHERE change_ref = ?", [self::UPPER, $otherPull]);
        }
        $this->judgeRound($fixture, self::PULL, SampleIdentity::NEW_HEAD);
        $fixture->entities->clear();

        self::assertSame($legacy, $fixture->transactions()->latestJudgedAt('alpha', self::PULL, SampleIdentity::HEAD)?->id()->toRfc4122());
    }

    public function testACanonicalJudgmentSupersedesAQueuedLegacyVerdictForTheSameHead(): void
    {
        $fixture = new RoundFixture(self::getContainer());
        $this->skipWhereTheAddressIgnoresLetterCase($fixture);
        $legacy = $this->judgeRound($fixture, 'https://github.com/acme/widgets/pull/8', SampleIdentity::HEAD);
        $this->renameChange($fixture, 'https://github.com/acme/widgets/pull/8', self::UPPER);
        $this->judgeRound($fixture, self::PULL, SampleIdentity::NEW_HEAD);
        $fixture->entities->clear();
        $rounds = $fixture->transactions();
        self::assertSame($legacy, $rounds->latestJudgedAt('alpha', self::PULL, SampleIdentity::HEAD)?->id()->toRfc4122());

        $canonical = $this->judgeRound($fixture, self::PULL, SampleIdentity::HEAD);

        self::assertSame($canonical, $rounds->latestJudgedAt('alpha', self::PULL, SampleIdentity::HEAD)->id()->toRfc4122());
        self::assertTrue($rounds->judgedAfter($fixture->round($legacy)));
    }

    public function testAStoredAliasWithASuffixStillSuppliesTheOnlyVerdictForTheListedHead(): void
    {
        $fixture = new RoundFixture(self::getContainer());
        $legacy = $this->judgeRound($fixture, 'https://github.com/acme/widgets/pull/8', SampleIdentity::HEAD);
        $this->renameChange($fixture, 'https://github.com/acme/widgets/pull/8', self::UPPER.'/?q=1#review');
        $this->judgeRound($fixture, self::PULL, SampleIdentity::NEW_HEAD);
        $fixture->entities->clear();

        self::assertSame($legacy, $fixture->transactions()->latestJudgedAt('alpha', self::PULL, SampleIdentity::HEAD)?->id()->toRfc4122());
    }

    public function testASeparateLocalBranchDifferingOnlyInCaseDoesNotSupplyAVerdict(): void
    {
        $fixture = new RoundFixture(self::getContainer());
        $this->skipWhereTheAddressIgnoresLetterCase($fixture);
        $this->judgeRound($fixture, 'Feature', SampleIdentity::HEAD);
        $fixture->entities->clear();

        self::assertNull($fixture->transactions()->latestJudgedAt('alpha', 'feature', SampleIdentity::HEAD));
    }

    public function testAnUnrelatedJudgedRoundWithAnEmptyVerdictIdentityDoesNotBreakTheLookupOfAnotherPullRequest(): void
    {
        $fixture = new RoundFixture(self::getContainer());
        $this->judgeRound($fixture, 'https://github.com/acme/widgets/pull/8', SampleIdentity::HEAD);
        $fixture->entities->getConnection()->executeStatement("UPDATE review_round SET verdict_identity = ''");
        $fixture->entities->clear();

        self::assertNull($fixture->transactions()->latestJudgedAt('alpha', self::PULL, SampleIdentity::HEAD));
    }

    private function skipWhereTheAddressIgnoresLetterCase(RoundFixture $fixture): void
    {
        if ($fixture->entities->getConnection()->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            self::markTestSkipped('MySQL and MariaDB compare the address without letter case, so two spellings that differ only in letter case share one row there.');
        }
    }

    private function renameChange(RoundFixture $fixture, string $from, string $to): void
    {
        foreach (['change_findings', 'review_round'] as $table) {
            $fixture->entities->getConnection()->executeStatement("UPDATE $table SET change_ref = ? WHERE change_ref = ?", [$to, $from]);
        }
    }

    private function judgeRound(RoundFixture $fixture, string $change, string $head): string
    {
        $sent = count($fixture->bus->messages);
        $judged = count($fixture->judgings());
        $round = $fixture->startAtHead($change, $head);
        $vendor = new VerdictPerRole([]);
        foreach (array_slice($fixture->bus->messages, $sent) as $message) {
            $fixture->deliver($message, $vendor);
        }
        foreach (array_slice($fixture->judgings(), $judged) as $message) {
            $fixture->judge($vendor)($message);
        }

        return $round;
    }
}
