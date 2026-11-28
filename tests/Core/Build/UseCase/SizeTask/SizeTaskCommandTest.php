<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Build\UseCase\SizeTask;

use DateTimeImmutable;
use Doctrine\Persistence\ManagerRegistry;
use Intakt\Core\Build\Contract\ReadinessGap;
use Intakt\Core\Build\Contract\SizeTier;
use Intakt\Core\Build\Contract\SizingRequest;
use Intakt\Core\Build\Contract\SizingVerdict;
use Intakt\Core\Build\UseCase\BuildTask\ParkedTasks;
use Intakt\Core\Build\UseCase\SizeTask\SizeTask;
use Intakt\Core\Build\UseCase\SizeTask\SizeTaskCommand;
use Intakt\Core\Build\UseCase\SizeTask\SizingOutcome;
use Intakt\Core\Build\UseCase\SizeTask\TaskNotSized;
use Intakt\Core\Ledger\Contract\AppendResult;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Plugin\GitHubForge\Contract\IssueLabels;
use Intakt\Plugin\GitHubForge\Contract\IssueReads;
use Intakt\Plugin\GitHubForge\Contract\ListedIssue;
use Intakt\Plugin\Vendor\Contract\ProviderUnreachable;
use Intakt\Plugin\Vendor\Contract\SizingVendor;
use Intakt\Tests\Core\Build\ParkedTaskTable;
use Intakt\Tests\Plugin\SymfonyAiVendor\InMemoryLedger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(SizeTaskCommand::class)]
#[UsesClass(SizeTask::class)]
#[UsesClass(TaskNotSized::class)]
#[UsesClass(SizingOutcome::class)]
#[UsesClass(SizingVerdict::class)]
#[UsesClass(SizingRequest::class)]
#[UsesClass(SizeTier::class)]
#[UsesClass(ReadinessGap::class)]
#[UsesClass(ParkedTasks::class)]
#[UsesClass(LedgerEvent::class)]
#[UsesClass(LedgerEventKind::class)]
#[UsesClass(AppendResult::class)]
final class SizeTaskCommandTest extends TestCase
{
    public function testAReadyTaskPrintsItsOutcome(): void
    {
        $tester = $this->tester(new SizingVerdict(true, [], SizeTier::Small, 10, 'Fine.'));

        $status = $tester->execute(['project' => 'alpha', 'issue' => '7']);

        self::assertSame([0, "outcome ready\n"], [$status, $tester->getDisplay()]);
    }

    public function testAnAnswerThatIsNoVerdictPrintsTheInvalidOutcome(): void
    {
        $tester = $this->tester(null);

        $status = $tester->execute(['project' => 'alpha', 'issue' => '7']);

        self::assertSame([0, "outcome invalid\n"], [$status, $tester->getDisplay()]);
    }

    public function testAnIssueNumberThatIsNoNumberIsRefusedBeforeAnyCall(): void
    {
        $tester = $this->tester(null);

        $status = $tester->execute(['project' => 'alpha', 'issue' => '07']);

        self::assertSame(1, $status);
        self::assertStringContainsString('"07" is not an issue number', $tester->getDisplay());
    }

    public function testAFailedProviderCallFailsTheCommandWithTheProvidersWords(): void
    {
        $vendor = self::createStub(SizingVendor::class);
        $vendor->method('size')->willThrowException(new ProviderUnreachable(new RuntimeException('no route')));

        $tester = $this->testerWith($vendor);
        $status = $tester->execute(['project' => 'alpha', 'issue' => '7']);

        self::assertSame(1, $status);
        self::assertStringContainsString('The sizing call failed: The model endpoint could not be reached.', $tester->getDisplay());
    }

    public function testATaskTheForgeDoesNotHoldFailsTheCommand(): void
    {
        $reads = self::createStub(IssueReads::class);
        $reads->method('readOpen')->willReturn(null);
        $ledger = new InMemoryLedger();
        $command = new SizeTaskCommand(new SizeTask($reads, self::createStub(SizingVendor::class), new ParkedTasks(ParkedTaskTable::inMemory(), $ledger, self::createStub(ManagerRegistry::class), new MockClock()), $ledger));

        $tester = new CommandTester($command);
        $status = $tester->execute(['project' => 'alpha', 'issue' => '7']);

        self::assertSame(1, $status);
        self::assertStringContainsString('it is not an open issue', $tester->getDisplay());
    }

    private function tester(?SizingVerdict $verdict): CommandTester
    {
        $vendor = self::createStub(SizingVendor::class);
        $vendor->method('size')->willReturn($verdict);

        return $this->testerWith($vendor);
    }

    private function testerWith(SizingVendor $vendor): CommandTester
    {
        $reads = self::createStub(IssueReads::class);
        $reads->method('readOpen')->willReturn(new ListedIssue(7, 'A task', 'Body', new IssueLabels(), 'owner', new DateTimeImmutable('2026-11-20 10:00:00'), null, false));
        $ledger = new InMemoryLedger();

        return new CommandTester(new SizeTaskCommand(new SizeTask($reads, $vendor, new ParkedTasks(ParkedTaskTable::inMemory(), $ledger, self::createStub(ManagerRegistry::class), new MockClock()), $ledger)));
    }
}
