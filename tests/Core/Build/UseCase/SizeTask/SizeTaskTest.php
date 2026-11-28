<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Build\UseCase\SizeTask;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use Intakt\Core\Build\Contract\ReadinessGap;
use Intakt\Core\Build\Contract\SizeTier;
use Intakt\Core\Build\Contract\SizingRequest;
use Intakt\Core\Build\Contract\SizingVerdict;
use Intakt\Core\Build\Domain\BuildQuestion;
use Intakt\Core\Build\Domain\ParkedTaskRecord;
use Intakt\Core\Build\UseCase\BuildTask\BuildRun;
use Intakt\Core\Build\UseCase\BuildTask\ParkedTasks;
use Intakt\Core\Build\UseCase\SizeTask\SizeTask;
use Intakt\Core\Build\UseCase\SizeTask\SizingOutcome;
use Intakt\Core\Build\UseCase\SizeTask\TaskNotSized;
use Intakt\Core\Ledger\Contract\AppendResult;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\GitHubForge\Contract\IssueLabels;
use Intakt\Plugin\GitHubForge\Contract\IssueReads;
use Intakt\Plugin\GitHubForge\Contract\ListedIssue;
use Intakt\Plugin\Vendor\Contract\SizingVendor;
use Intakt\Tests\Core\Build\ParkedTaskTable;
use Intakt\Tests\Plugin\SymfonyAiVendor\InMemoryLedger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

#[CoversClass(SizeTask::class)]
#[CoversClass(TaskNotSized::class)]
#[CoversClass(SizingOutcome::class)]
#[UsesClass(SizingVerdict::class)]
#[UsesClass(SizingRequest::class)]
#[UsesClass(SizeTier::class)]
#[UsesClass(ReadinessGap::class)]
#[UsesClass(BuildQuestion::class)]
#[UsesClass(BuildRun::class)]
#[UsesClass(ParkedTasks::class)]
#[UsesClass(ParkedTaskRecord::class)]
#[UsesClass(LedgerEvent::class)]
#[UsesClass(LedgerEventKind::class)]
#[UsesClass(AppendResult::class)]
final class SizeTaskTest extends TestCase
{
    public function testAReadyTaskGetsAStoredSizeEstimateAndIsNotParked(): void
    {
        $ledger = new InMemoryLedger();
        $parked = ParkedTaskTable::inMemory();

        $outcome = $this->sizing($this->verdict([], SizeTier::Medium, 240), $ledger, $parked)->run('alpha', 7);

        self::assertSame(SizingOutcome::Ready, $outcome);
        self::assertSame([['project' => 'alpha', 'task' => '7', 'outcome' => 'ready', 'missing' => [], 'size' => 'medium', 'estimated_lines' => 240]], $ledger->payloads());
        self::assertSame([LedgerEventKind::TaskSized], $ledger->kinds());
        self::assertSame([], $parked->fetchAllAssociative('SELECT * FROM build_parked_task'));
    }

    public function testATaskWithoutAnAcceptanceConditionIsParkedWithExactlyOneQuestion(): void
    {
        $ledger = new InMemoryLedger();
        $parked = ParkedTaskTable::inMemory();

        $outcome = $this->sizing($this->verdict([ReadinessGap::AcceptanceCondition], SizeTier::Small, 20), $ledger, $parked)->run('alpha', 7);

        self::assertSame(SizingOutcome::Parked, $outcome);
        self::assertSame('"This task is not ready to build. It lacks: an acceptance condition."', $parked->fetchOne('SELECT question FROM build_parked_task'));
        self::assertSame(1, $parked->fetchOne('SELECT COUNT(*) FROM build_parked_task'));
        self::assertSame([LedgerEventKind::TaskSized, LedgerEventKind::BuildStepEnded, LedgerEventKind::TaskParked], $ledger->kinds());
    }

    public function testATaskWithTwoOutcomesIsParkedNamingTheMissingItem(): void
    {
        $parked = ParkedTaskTable::inMemory();

        (void) $this->sizing($this->verdict([ReadinessGap::SingleOutcome], SizeTier::Large, 900), new InMemoryLedger(), $parked)->run('alpha', 7);

        $question = $parked->fetchOne('SELECT question FROM build_parked_task');
        self::assertIsString($question);
        self::assertStringContainsString('a single outcome', $question);
    }

    public function testAnInvalidSizingAnswerParksNothingAndSplitsNothing(): void
    {
        $ledger = new InMemoryLedger();
        $parked = ParkedTaskTable::inMemory();

        $outcome = $this->sizing(null, $ledger, $parked)->run('alpha', 7);

        self::assertSame(SizingOutcome::Invalid, $outcome);
        self::assertSame([['project' => 'alpha', 'task' => '7', 'outcome' => 'invalid', 'missing' => [], 'size' => null, 'estimated_lines' => null]], $ledger->payloads());
        self::assertSame([], $parked->fetchAllAssociative('SELECT * FROM build_parked_task'));
    }

    public function testAnIssueThatIsNotOpenIsNotSizedAndCostsNoCall(): void
    {
        $reads = self::createStub(IssueReads::class);
        $reads->method('readOpen')->willReturn(null);
        $vendor = $this->createMock(SizingVendor::class);
        $vendor->expects(self::never())->method('size');

        try {
            (void) new SizeTask($reads, $vendor, $this->parkedTasks(ParkedTaskTable::inMemory(), new InMemoryLedger()), new InMemoryLedger())->run('alpha', 7);
            self::fail('An issue that is not open was sized.');
        } catch (TaskNotSized $notSized) {
            self::assertSame('Issue 7 of project "alpha" cannot be sized: it is not an open issue', $notSized->getMessage());
        }
    }

    public function testAnIssueTheForgeCannotReadIsNotSized(): void
    {
        $reads = self::createStub(IssueReads::class);
        $reads->method('readOpen')->willThrowException(new ForgeReadFailed('the forge is down'));

        try {
            (void) new SizeTask($reads, self::createStub(SizingVendor::class), $this->parkedTasks(ParkedTaskTable::inMemory(), new InMemoryLedger()), new InMemoryLedger())->run('alpha', 7);
            self::fail('An issue the forge could not read was sized.');
        } catch (TaskNotSized $notSized) {
            self::assertSame('Issue 7 of project "alpha" cannot be sized: the forge is down', $notSized->getMessage());
        }
    }

    public function testAVerdictTheLedgerDoesNotRecordParksNothing(): void
    {
        $parked = ParkedTaskTable::inMemory();

        try {
            (void) $this->sizing($this->verdict([ReadinessGap::ObservableResult], SizeTier::Small, 10), new InMemoryLedger(0), $parked)->run('alpha', 7);
            self::fail('A verdict the ledger refused was acted on.');
        } catch (TaskNotSized $notSized) {
            self::assertStringContainsString('did not record the sizing', $notSized->getMessage());
        }

        self::assertSame([], $parked->fetchAllAssociative('SELECT * FROM build_parked_task'));
    }

    /**
     * @param list<ReadinessGap> $missing
     */
    private function verdict(array $missing, SizeTier $size, int $lines): SizingVerdict
    {
        return new SizingVerdict([] === $missing, $missing, $size, $lines, 'Because.');
    }

    private function sizing(?SizingVerdict $verdict, InMemoryLedger $ledger, Connection $parked): SizeTask
    {
        $reads = self::createStub(IssueReads::class);
        $reads->method('readOpen')->willReturn(new ListedIssue(7, 'A task', 'Body', new IssueLabels(), 'owner', new DateTimeImmutable('2026-11-20 10:00:00'), null, false));
        $vendor = self::createStub(SizingVendor::class);
        $vendor->method('size')->willReturn($verdict);

        return new SizeTask($reads, $vendor, $this->parkedTasks($parked, $ledger), $ledger);
    }

    private function parkedTasks(Connection $connection, InMemoryLedger $ledger): ParkedTasks
    {
        return new ParkedTasks($connection, $ledger, self::createStub(ManagerRegistry::class), new MockClock('2026-11-20 10:00:00 UTC'));
    }
}
