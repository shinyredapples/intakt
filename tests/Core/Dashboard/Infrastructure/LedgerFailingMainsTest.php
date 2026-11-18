<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Infrastructure;

use Intakt\Core\Dashboard\Domain\FailingMain;
use Intakt\Core\Dashboard\Infrastructure\LedgerFailingMains;
use Intakt\Core\Dashboard\Infrastructure\LedgerPayload;
use Intakt\Tests\Core\Dashboard\UseCase\DashboardLedger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(LedgerFailingMains::class)]
#[CoversClass(FailingMain::class)]
#[UsesClass(LedgerPayload::class)]
final class LedgerFailingMainsTest extends TestCase
{
    private DashboardLedger $ledger;

    protected function setUp(): void
    {
        $this->ledger = new DashboardLedger();
    }

    public function testAMainThatFailsToBootIsOneItemOfItsProject(): void
    {
        $this->ledger->failMainBoot('alpha', 'No status.');
        $this->ledger->failMainBoot('alpha', 'Still no status.');

        $mains = $this->failing();

        self::assertCount(1, $mains);
        self::assertSame(['alpha', 'Still no status.'], [$mains[0]->project, $mains[0]->reason]);
    }

    public function testALaterBootedMainOfTheSameProjectClearsTheItem(): void
    {
        $this->ledger->failMainBoot('alpha', 'No status.');
        $this->ledger->failMainBoot('beta', 'No status.');

        $this->ledger->bootMain('alpha');

        self::assertSame(['beta'], array_map(static fn (FailingMain $main): string => $main->project, $this->failing()));
    }

    public function testABootedResultOfTheSameMainAndToolsDoesNotClearTheItem(): void
    {
        $this->ledger->failMainBoot('alpha', 'No status.');

        $this->ledger->bootMain('alpha', 'a');

        self::assertSame(['alpha'], array_map(static fn (FailingMain $main): string => $main->project, $this->failing()));
    }

    public function testABootedResultOfTheSameMainWithOtherToolsClearsTheItem(): void
    {
        $this->ledger->failMainBoot('alpha', 'No status.');

        $this->ledger->bootMain('alpha', 'a', 'tools-2');

        self::assertSame([], $this->failing());
    }

    public function testALateBootedLineOfAnOlderFailedMainKeepsTheItemOfTheNewerFailingMain(): void
    {
        $this->ledger->failMainBoot('alpha', 'A fails.', 'a');
        $this->ledger->failMainBoot('alpha', 'B fails.', 'b');

        $this->ledger->bootMain('alpha', 'a');

        self::assertSame(['B fails.'], array_map(static fn (FailingMain $main): string => $main->reason, $this->failing()));
    }

    public function testALateBootedLineOfAnOlderBootedMainKeepsTheItemOfTheNewerFailingMain(): void
    {
        $this->ledger->bootMain('alpha', 'a');
        $this->ledger->failMainBoot('alpha', 'B fails.', 'b');

        $this->ledger->bootMain('alpha', 'a');

        self::assertSame(['B fails.'], array_map(static fn (FailingMain $main): string => $main->reason, $this->failing()));
    }

    public function testAFailedMainReadFromTheStoreAfterABootedOneShowsTheItemAgainWithItsReason(): void
    {
        $this->ledger->failMainBoot('alpha', 'A fails.', 'a');
        $this->ledger->bootMain('alpha', 'b');

        $this->ledger->readMainFromStore('alpha', 'a', 'failed', 'A fails.');

        self::assertSame([['a', 'A fails.']], array_map(static fn (FailingMain $main): array => [$main->main, $main->reason], $this->failing()));
    }

    public function testABootedMainReadFromTheStoreClearsTheItemThoughItsPairWasSeen(): void
    {
        $this->ledger->bootMain('alpha', 'b');
        $this->ledger->failMainBoot('alpha', 'A fails.', 'a');

        $this->ledger->readMainFromStore('alpha', 'b', 'booted');

        self::assertSame([], $this->failing());
    }

    public function testAComputedFailedLineThatWasNotReadFromTheStoreRaisesNoItem(): void
    {
        $this->ledger->bootMain('alpha', 'b');

        $this->ledger->computeFailedMain('alpha', 'a', 'A fails.');

        self::assertSame([], $this->failing());
    }

    public function testACrashReadFromTheStoreNeitherRaisesNorClearsTheItem(): void
    {
        $this->ledger->failMainBoot('alpha', 'A fails.', 'a');

        $this->ledger->readMainFromStore('alpha', 'b', 'crashed', 'composer could not run');

        self::assertSame(['A fails.'], array_map(static fn (FailingMain $main): string => $main->reason, $this->failing()));
    }

    public function testALedgerWithoutAMainFailureListsNothing(): void
    {
        $this->ledger->bootMain('alpha');

        self::assertSame([], $this->failing());
    }

    /**
     * @return list<FailingMain>
     */
    private function failing(): array
    {
        return new LedgerFailingMains()->in($this->ledger->readInOrder());
    }
}
