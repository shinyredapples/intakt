<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Watcher\Contract;

use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\PayloadFields;
use Intakt\Core\Watcher\Contract\HandedOverChanges;
use Intakt\Tests\Core\Git\UseCase\PushBranch\RecordingLedger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(HandedOverChanges::class)]
#[UsesClass(PayloadFields::class)]
final class HandedOverChangesTest extends TestCase
{
    private const string PULL = 'https://github.com/acme/widgets/pull/7';

    private RecordingLedger $ledger;

    public function testAChangeWithAHandOverLineOfItsProjectIsHandedToIntakt(): void
    {
        $this->record(LedgerEventKind::ChangeHandedToIntakt, 'alpha', self::PULL);

        self::assertTrue($this->handed()->isHandedToIntakt('alpha', self::PULL));
    }

    public function testAHandOverLineInAnotherLetterCaseNamesTheSameChange(): void
    {
        $this->record(LedgerEventKind::ChangeHandedToIntakt, 'alpha', 'https://github.com/Acme/Widgets/pull/7');

        self::assertTrue($this->handed()->isHandedToIntakt('alpha', self::PULL));
    }

    public function testAHandOverLineOfAnotherProjectDoesNotHandTheChangeOver(): void
    {
        $this->record(LedgerEventKind::ChangeHandedToIntakt, 'beta', self::PULL);

        self::assertFalse($this->handed()->isHandedToIntakt('alpha', self::PULL));
    }

    public function testAHandOverLineOfAnotherChangeDoesNotHandTheChangeOver(): void
    {
        $this->record(LedgerEventKind::ChangeHandedToIntakt, 'alpha', 'https://github.com/acme/widgets/pull/70');

        self::assertFalse($this->handed()->isHandedToIntakt('alpha', self::PULL));
    }

    public function testALineThatSaysTheChangeIsNotHandedOverDoesNotHandItOver(): void
    {
        $this->record(LedgerEventKind::ChangeNotHandedToIntakt, 'alpha', self::PULL);

        self::assertFalse($this->handed()->isHandedToIntakt('alpha', self::PULL));
    }

    public function testAnUnreadableLineIsSkippedForTheReadableOnes(): void
    {
        $this->ledger->append(new LedgerEvent(LedgerEventKind::ChangeHandedToIntakt, '{not json'));
        $this->record(LedgerEventKind::ChangeHandedToIntakt, 'alpha', self::PULL);

        self::assertTrue($this->handed()->isHandedToIntakt('alpha', self::PULL));
    }

    protected function setUp(): void
    {
        $this->ledger = new RecordingLedger();
    }

    private function record(LedgerEventKind $kind, string $project, string $change): void
    {
        $this->ledger->append(new LedgerEvent($kind, json_encode(['project' => $project, 'change' => $change, 'head' => str_repeat('a', 40)], JSON_THROW_ON_ERROR)));
    }

    private function handed(): HandedOverChanges
    {
        return new HandedOverChanges($this->ledger);
    }
}
