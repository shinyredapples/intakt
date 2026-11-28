<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Build\Infrastructure;

use Intakt\Core\Build\Contract\UmbrellaTasks;
use Intakt\Core\Build\Infrastructure\RecordedSplitUmbrellas;
use Intakt\Core\Build\UseCase\SizeTask\SizingRecords;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Tests\Plugin\SymfonyAiVendor\InMemoryLedger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RecordedSplitUmbrellas::class)]
#[CoversClass(UmbrellaTasks::class)]
#[UsesClass(SizingRecords::class)]
final class RecordedSplitUmbrellasTest extends TestCase
{
    public function testATaskWithARecordedSplitPlanIsAnUmbrella(): void
    {
        $umbrellas = $this->umbrellasOf($this->planned('alpha', '7'));

        self::assertTrue($umbrellas->of('alpha')->contains(7));
    }

    public function testATaskWithoutASplitPlanIsNoUmbrella(): void
    {
        $umbrellas = $this->umbrellasOf($this->planned('alpha', '7'));

        self::assertFalse($umbrellas->of('alpha')->contains(8));
    }

    public function testAPlanOfTheSameNumberInAnotherProjectMakesNoUmbrella(): void
    {
        $umbrellas = $this->umbrellasOf($this->planned('beta', '7', '{"project":"beta","note":"alpha"}'));

        self::assertFalse($umbrellas->of('alpha')->contains(7));
    }

    public function testAFinishedSplitWithoutItsPlanMakesNoUmbrella(): void
    {
        $ledger = new InMemoryLedger();
        $ledger->append(new LedgerEvent(LedgerEventKind::TaskSplit, '{"project":"alpha","task":"7","run":"r1","parts":[100,101]}', SizingRecords::keyOf('alpha', 7)));

        self::assertFalse(new RecordedSplitUmbrellas($ledger)->of('alpha')->contains(7));
    }

    public function testAPlanWhoseTaskIsNoNumberMakesNoUmbrella(): void
    {
        $umbrellas = $this->umbrellasOf(
            new LedgerEvent(LedgerEventKind::TaskSplitPlanned, '{"project":"alpha","task":"7a"}', SizingRecords::keyOf('alpha', 7)),
            new LedgerEvent(LedgerEventKind::TaskSplitPlanned, '{"project":"alpha","task":8}', SizingRecords::keyOf('alpha', 8)),
            new LedgerEvent(LedgerEventKind::TaskSplitPlanned, 'not json "alpha"', SizingRecords::keyOf('alpha', 9)),
        );

        self::assertFalse($umbrellas->of('alpha')->contains(7));
        self::assertFalse($umbrellas->of('alpha')->contains(8));
        self::assertFalse($umbrellas->of('alpha')->contains(9));
    }

    private function planned(string $project, string $task, ?string $payload = null): LedgerEvent
    {
        $payload ??= json_encode(['project' => $project, 'task' => $task, 'size' => 'split', 'run' => 'r1', 'parts' => [], 'labels' => []], JSON_THROW_ON_ERROR);

        return new LedgerEvent(LedgerEventKind::TaskSplitPlanned, $payload, SizingRecords::keyOf($project, (int) $task));
    }

    private function umbrellasOf(LedgerEvent ...$events): RecordedSplitUmbrellas
    {
        $ledger = new InMemoryLedger();
        foreach ($events as $event) {
            $ledger->append($event);
        }

        return new RecordedSplitUmbrellas($ledger);
    }
}
