<?php

declare(strict_types=1);

namespace Intakt\Tests\Application;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use DoctrineMigrations\Version20261122100000;
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
final class LegacyTimelineNextNumberTest extends KernelTestCase
{
    private const string PULL = 'https://github.com/acme/widgets/pull/7';
    private const string OLD_SPELLING = 'https://github.com/Acme/Widgets/pull/7';
    private const string SHOUTED_SPELLING = 'https://github.com/ACME/WIDGETS/pull/7';
    private const string OTHER_PULL = 'https://github.com/acme/widgets/pull/8';

    public function testALegacyTimelineKeptApartBesideALowercaseRowOffersNoStartRound(): void
    {
        $fixture = new RoundFixture(self::getContainer());
        $this->refuseOnMySql();
        $fixture->startAtHead(self::PULL, SampleIdentity::HEAD);
        $legacy = '';
        for ($round = 0; $round < 3; ++$round) {
            $legacy = $fixture->startAtHead(self::OTHER_PULL, SampleIdentity::HEAD);
        }
        $this->respell(self::OTHER_PULL, self::OLD_SPELLING);

        $this->migrate();

        $review = self::getContainer()->get(LedgerReviewPages::class)->reviewOf($legacy);
        self::assertSame([self::OLD_SPELLING, null], [$review?->change, $review?->nextNumber()]);
    }

    public function testALegacyTimelineOffersNoStartRoundWhenNoOldSpellingWasLowercaseAndStorageStartsTheCanonicalSequence(): void
    {
        $fixture = new RoundFixture(self::getContainer());
        $this->refuseOnMySql();
        $fixture->startAtHead(self::PULL, SampleIdentity::HEAD);
        $legacy = $fixture->startAtHead(self::PULL, SampleIdentity::HEAD);
        $fixture->startAtHead(self::OTHER_PULL, SampleIdentity::HEAD);
        $this->respell(self::PULL, self::OLD_SPELLING);
        $this->respell(self::OTHER_PULL, self::SHOUTED_SPELLING);

        $this->migrate();
        $canonical = $fixture->round($fixture->startAtHead(self::OLD_SPELLING, SampleIdentity::HEAD));

        $review = self::getContainer()->get(LedgerReviewPages::class)->reviewOf($legacy);
        self::assertSame([1, self::OLD_SPELLING, null], [$canonical->number(), $review?->change, $review?->nextNumber()]);
    }

    private function refuseOnMySql(): void
    {
        if ($this->connection()->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            self::markTestSkipped('MySQL and MariaDB compare the address without letter case, so two spellings of one change cannot be stored apart.');
        }
    }

    // Written as a change stored before rounds were stored canonically would have been, its start rows too, because the ledger is append-only.
    private function respell(string $from, string $to): void
    {
        $connection = $this->connection();

        foreach (['change_findings', 'review_round'] as $table) {
            $connection->executeStatement(sprintf('UPDATE %s SET change_ref = ? WHERE change_ref = ?', $table), [$to, $from]);
        }

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

    private function connection(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.default_connection');
    }
}
