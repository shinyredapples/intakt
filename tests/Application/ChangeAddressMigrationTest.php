<?php

declare(strict_types=1);

namespace Intakt\Tests\Application;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use DoctrineMigrations\Version20261122100000;
use Intakt\Core\Dashboard\Domain\ReviewedRound;
use Intakt\Core\Dashboard\Infrastructure\LedgerReviewPages;
use Intakt\Tests\Core\Review\SampleIdentity;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\RoundFixture;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\ApplicationTester;

// Covers nothing because the migrations bundle loads migrations by path, so their classes do not exist when PHPUnit reads the attributes.
#[Group('persistence')]
#[CoversNothing]
final class ChangeAddressMigrationTest extends KernelTestCase
{
    private const string PULL = 'https://github.com/acme/widgets/pull/7';
    private const string OLD_SPELLING = 'https://github.com/Acme/Widgets/pull/7';
    private const string OTHER_PULL = 'https://github.com/acme/widgets/pull/8';

    public function testARoundStoredUnderAnotherSpellingBeforeTheMigrationContinuesItsNumberingAfterIt(): void
    {
        $fixture = new RoundFixture(self::getContainer());
        $old = $fixture->startAtHead(self::PULL, SampleIdentity::HEAD);
        $this->respell(self::PULL, self::OLD_SPELLING);

        $this->migrate();
        $next = $fixture->round($fixture->startAtHead(self::OLD_SPELLING, SampleIdentity::HEAD));

        self::assertSame([self::PULL, 2], [$fixture->round($old)->change(), $next->number()]);
    }

    public function testOldSpellingsOfOneChangeThatWouldCollideKeepTheirOwnRowsAndNewRoundsContinueTheCanonicalOne(): void
    {
        $fixture = new RoundFixture(self::getContainer());
        $this->refuseOnMySql();
        $fixture->startAtHead(self::PULL, SampleIdentity::HEAD);
        $apart = $fixture->startAtHead(self::OTHER_PULL, SampleIdentity::HEAD);
        $this->respell(self::OTHER_PULL, self::OLD_SPELLING);

        $this->migrate();
        $next = $fixture->round($fixture->startAtHead(self::OLD_SPELLING, SampleIdentity::HEAD));

        self::assertSame([self::OLD_SPELLING, 2], [$fixture->round($apart)->change(), $next->number()]);
    }

    public function testAMigratedPullRequestShowsItsRoundsFromBeforeAndAfterTheMigrationAsOneReview(): void
    {
        $fixture = new RoundFixture(self::getContainer());
        $old = $fixture->startAtHead(self::PULL, SampleIdentity::HEAD);
        $this->respell(self::PULL, self::OLD_SPELLING);
        $this->respellStartRows(self::PULL, self::OLD_SPELLING);

        $this->migrate();
        $next = $fixture->startAtHead(self::OLD_SPELLING, SampleIdentity::HEAD);

        $review = self::getContainer()->get(LedgerReviewPages::class)->reviewOf($next);
        self::assertSame([$old, $next], array_map(static fn (ReviewedRound $round): string => $round->round, $review->rounds ?? []));
    }

    public function testTheNewRoundOfACanonicalSequenceStaysTheLatestOfItsReviewBesideALongerCollidingOldSpelling(): void
    {
        $fixture = new RoundFixture(self::getContainer());
        $this->refuseOnMySql();
        $first = $fixture->startAtHead(self::PULL, SampleIdentity::HEAD);
        for ($round = 0; $round < 3; ++$round) {
            $fixture->startAtHead(self::OTHER_PULL, SampleIdentity::HEAD);
        }
        $this->respell(self::OTHER_PULL, self::OLD_SPELLING);
        $this->respellStartRows(self::OTHER_PULL, self::OLD_SPELLING);

        $this->migrate();
        $next = $fixture->startAtHead(self::PULL, SampleIdentity::HEAD);

        $review = self::getContainer()->get(LedgerReviewPages::class)->reviewOf($next);
        self::assertSame([[$first, $next], 3], [array_map(static fn (ReviewedRound $round): string => $round->round, $review->rounds ?? []), $review?->nextNumber()]);
    }

    public function testALocalBranchWithCapitalLettersKeepsItsSpellingThroughTheMigration(): void
    {
        $fixture = new RoundFixture(self::getContainer());
        $round = $fixture->startAtHead('feat/Widget', SampleIdentity::HEAD);

        $this->migrate();

        self::assertSame('feat/Widget', $fixture->round($round)->change());
    }

    public function testAnOldRoundWhoseSpellingCollidedStillReadsItsOwnRoundSequence(): void
    {
        $fixture = new RoundFixture(self::getContainer());
        $this->refuseOnMySql();
        $fixture->startAtHead(self::PULL, SampleIdentity::HEAD);
        $apart = $fixture->startAtHead(self::OTHER_PULL, SampleIdentity::HEAD);
        $this->respell(self::OTHER_PULL, self::OLD_SPELLING);

        $this->migrate();

        self::assertSame([$apart], array_map(static fn ($round): string => $round->id()->toRfc4122(), $fixture->transactions()->ofChange($fixture->round($apart))));
    }

    // Written as a change stored before rounds were stored canonically would have been.
    private function respell(string $from, string $to): void
    {
        foreach (['change_findings', 'review_round'] as $table) {
            $this->connection()->executeStatement(sprintf('UPDATE %s SET change_ref = ? WHERE change_ref = ?', $table), [$to, $from]);
        }
    }

    // The ledger is append-only, so a start row written before the migration keeps the spelling it named.
    private function respellStartRows(string $from, string $to): void
    {
        $connection = $this->connection();

        foreach ($connection->fetchFirstColumn("SELECT payload FROM ledger_event WHERE kind = 'review_started'") as $stored) {
            self::assertIsString($stored);
            $payload = json_decode($stored, true, flags: JSON_THROW_ON_ERROR);
            self::assertIsArray($payload);

            if ($from === $payload['change']) {
                $payload['change'] = $to;
                $connection->update('ledger_event', ['payload' => json_encode($payload, JSON_THROW_ON_ERROR)], ['payload' => $stored]);
            }
        }
    }

    private function migrate(): void
    {
        $application = new Application(self::$kernel ?? self::bootKernel());
        $application->setAutoExit(false);
        $console = new ApplicationTester($application);
        $console->run(['command' => 'doctrine:migrations:execute', 'versions' => [Version20261122100000::class], '--up' => true, '--no-interaction' => true]);
        $console->assertCommandIsSuccessful($console->getDisplay());
        self::getContainer()->get('doctrine.orm.entity_manager')->clear();
    }

    private function refuseOnMySql(): void
    {
        if ($this->connection()->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            self::markTestSkipped('MySQL and MariaDB compare the address without letter case, so two spellings of one change cannot be stored apart.');
        }
    }

    private function connection(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.default_connection');
    }
}
