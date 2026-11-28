<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubWorkSource\Infrastructure;

use Intakt\Core\Build\Contract\FinishedSplit;
use Intakt\Core\Build\Contract\FinishedSplits;
use Intakt\Core\Build\Contract\UmbrellaTasks;
use Intakt\Core\Build\Infrastructure\RecordedSplitUmbrellas;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Plugin\Forge\Contract\ForgeWriteFailed;
use Intakt\Plugin\GitHubForge\Contract\KeyedComment;
use Intakt\Plugin\GitHubForge\Contract\TaskParts;
use Intakt\Plugin\GitHubWorkSource\Domain\ClosedUmbrellas;
use Intakt\Plugin\GitHubWorkSource\Domain\IntakePlan;
use Intakt\Plugin\GitHubWorkSource\Infrastructure\UmbrellaClosing;
use Intakt\Tests\Plugin\GitHubWorkSource\RecordedTaskCloses;
use Intakt\Tests\Plugin\SymfonyAiVendor\InMemoryLedger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\ErrorHandler\BufferingLogger;

#[CoversClass(UmbrellaClosing::class)]
#[CoversClass(ClosedUmbrellas::class)]
#[UsesClass(IntakePlan::class)]
#[UsesClass(RecordedSplitUmbrellas::class)]
#[UsesClass(UmbrellaTasks::class)]
#[UsesClass(FinishedSplit::class)]
#[UsesClass(FinishedSplits::class)]
#[UsesClass(KeyedComment::class)]
final class UmbrellaClosingTest extends TestCase
{
    private InMemoryLedger $ledger;
    private RecordedTaskCloses $closes;
    private BufferingLogger $logger;

    /** @var list<int> */
    private array $filedParts = [201, 202];

    public function testAnUmbrellaWhosePartsAreAllClosedIsClosedOnce(): void
    {
        $this->finished('{"project":"alpha","task":"7","run":"r1","parts":[201,202]}');

        $result = $this->closeOver(7, 30);

        self::assertEquals([new FinishedSplit(7, 201, 202)], $result->closed);
        self::assertEquals([['project' => 'alpha', 'number' => 7, 'comment' => new KeyedComment('All parts are closed (#201, #202), so this umbrella is closed.', 'umbrella-closed')]], $this->closes->closed);
    }

    public function testAnUmbrellaStaysOpenWhileOneOfItsPartsIsOpen(): void
    {
        $this->finished('{"project":"alpha","task":"7","run":"r1","parts":[201,202]}');

        $result = $this->closeOver(7, 202);

        self::assertSame([], $result->closed);
        self::assertSame([], $this->closes->closed);
    }

    public function testThePartsComeFromTheFinishedSplitAndNeverFromAWaitsForLineOrAComment(): void
    {
        // Only the plan: the umbrella's comment and any `Waits for #201` in its text name parts, and #201 is closed, yet nothing the ledger finished names them.
        $this->ledger->append(new LedgerEvent(LedgerEventKind::TaskSplitPlanned, '{"project":"alpha","task":"7","run":"r1","parts":[],"labels":[]}', 'split-7'));

        $result = $this->closeOver(7);

        self::assertSame([], $result->closed);
        self::assertSame([], $this->closes->closed);
    }

    public function testAnUmbrellaTheListingNoLongerShowsOpenIsNotClosedAgain(): void
    {
        $this->finished('{"project":"alpha","task":"7","run":"r1","parts":[201,202]}');

        $result = $this->closeOver(30);

        self::assertSame([], $result->closed);
        self::assertSame([], $this->closes->closed);
    }

    public function testAPartThisRepositoryDidNotFileKeepsTheUmbrellaOpen(): void
    {
        $this->filedParts = [201];
        $this->finished('{"project":"alpha","task":"7","run":"r1","parts":[201,202]}');

        $result = $this->closeOver(7);

        self::assertSame([], $result->closed);
        self::assertSame([], $this->closes->closed);
    }

    public function testAFinishedSplitOfAnotherProjectClosesNothing(): void
    {
        $this->finished('{"project":"beta","task":"7","run":"r1","parts":[201,202],"note":"alpha"}');

        $result = $this->closeOver(7);

        self::assertSame([], $result->closed);
    }

    public function testAFailedCloseIsLoggedAndTriedAgainOnTheNextTick(): void
    {
        $this->finished('{"project":"alpha","task":"7","run":"r1","parts":[201,202]}');
        $this->closes->refusal = new ForgeWriteFailed('GitHub answered 502 to a write.');

        $failed = $this->closeOver(7);

        self::assertSame([7 => 'GitHub answered 502 to a write.'], $failed->stayedOpen);
        $logs = $this->logger->cleanLogs();
        self::assertCount(1, $logs);
        self::assertIsArray($logs[0]);
        self::assertSame('warning', $logs[0][0]);

        $this->closes->refusal = null;
        $retried = $this->closeOver(7);

        self::assertEquals([new FinishedSplit(7, 201, 202)], $retried->closed);
        self::assertSame([7], array_column($this->closes->closed, 'number'));
    }

    protected function setUp(): void
    {
        $this->ledger = new InMemoryLedger();
        $this->closes = new RecordedTaskCloses();
        $this->logger = new BufferingLogger();
    }

    protected function tearDown(): void
    {
        $this->logger->cleanLogs();
    }

    private function finished(string $payload): void
    {
        $this->ledger->append(new LedgerEvent(LedgerEventKind::TaskSplit, $payload, 'split-7'));
    }

    private function closeOver(int ...$open): ClosedUmbrellas
    {
        $parts = self::createStub(TaskParts::class);
        $parts->method('isFiledPart')->willReturnCallback(fn (string $project, int $issue): bool => in_array($issue, $this->filedParts, true));

        return new UmbrellaClosing(new RecordedSplitUmbrellas($this->ledger), $parts, $this->closes, $this->logger)->closeFinished('alpha', new IntakePlan([], [], [], [], [], [], array_values($open)));
    }
}
