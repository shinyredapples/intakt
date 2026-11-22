<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Budget\UseCase\ReplayDay;

use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Budget\Contract\SharedHold;
use Intakt\Core\Budget\Contract\StartDecision;
use Intakt\Core\Budget\Contract\Usage;
use Intakt\Core\Budget\Contract\UsageWindow;
use Intakt\Core\Budget\Contract\WindowReading;
use Intakt\Core\Budget\Contract\WindowUse;
use Intakt\Core\Budget\Domain\BudgetGuard;
use Intakt\Core\Budget\Domain\CeilingStartGuard;
use Intakt\Core\Budget\Domain\OutageWatch;
use Intakt\Core\Budget\UseCase\ReplayDay\DayReplay;
use Intakt\Core\Budget\UseCase\ReplayDay\ScriptedStep;
use Intakt\Core\Budget\UseCase\ReplayDay\ScriptLineNotUnderstood;
use Intakt\Tests\Core\Budget\HoldsInMemory;
use Intakt\Tests\Core\Budget\ReadingsInMemory;
use Intakt\Tests\Core\Watchdog\HaltsInMemory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\EventDispatcher\EventDispatcher;

#[CoversClass(DayReplay::class)]
#[CoversClass(ScriptedStep::class)]
#[CoversClass(ScriptLineNotUnderstood::class)]
#[UsesClass(BudgetGuard::class)]
#[UsesClass(CeilingStartGuard::class)]
#[UsesClass(OutageWatch::class)]
#[UsesClass(SharedHold::class)]
#[UsesClass(StartDecision::class)]
#[UsesClass(Account::class)]
#[UsesClass(Usage::class)]
#[UsesClass(UsageWindow::class)]
#[UsesClass(WindowReading::class)]
#[UsesClass(WindowUse::class)]
final class DayReplayTest extends TestCase
{
    private DayReplay $replay;

    protected function setUp(): void
    {
        $clock = new MockClock('2026-11-02 08:00:00 UTC');
        $readings = new ReadingsInMemory($clock);
        $holds = new HoldsInMemory($clock);
        $guard = new BudgetGuard($holds, new CeilingStartGuard($readings, $clock, ['five-hour' => 90.0, 'weekly' => 90.0]), new OutageWatch($readings, $clock, new EventDispatcher()), new HaltsInMemory());
        $this->replay = new DayReplay($readings, $holds, $guard);
    }

    public function testEachStepAnswersWithItsOutcome(): void
    {
        self::assertSame('recorded', $this->play('2026-11-02T08:00 read claude_code.main five-hour 20 2026-11-02T13:00'));
        self::assertSame('recorded', $this->play('2026-11-02T08:00 limit claude_code.main 2026-11-02T09:00'));
        self::assertSame('cooling-down', $this->play('2026-11-02T08:00 start claude_code.main 0'));
        self::assertSame('held', $this->play('2026-11-02T08:00 hold drain 2026-11-02T09:00'));
        self::assertSame('draining', $this->play('2026-11-02T08:00 merge'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function linesNotUnderstood(): iterable
    {
        yield 'an unknown step' => ['2026-11-02T08:00 sleep', 'a step is read, limit, hold, start or merge'];
        yield 'no step' => ['2026-11-02T08:00', 'a step is read, limit, hold, start or merge'];
        yield 'a missing argument' => ['2026-11-02T08:00 start claude_code.main', 'start takes 2 arguments'];
        yield 'an extra argument' => ['2026-11-02T08:00 merge now', 'merge takes 0 arguments'];
        yield 'an unknown provider' => ['2026-11-02T08:00 start claude.main 0', 'an account is written as provider.name'];
        yield 'an account without a name' => ['2026-11-02T08:00 start claude_code 0', 'an account is written as provider.name'];
        yield 'an account name the configuration refuses' => ['2026-11-02T08:00 start claude_code.Main 0', 'an account is written as provider.name'];
        yield 'an unknown window' => ['2026-11-02T08:00 read claude_code.main daily 20 2026-11-02T13:00', 'a window is one of five-hour, weekly'];
        yield 'a negative percent' => ['2026-11-02T08:00 read claude_code.main weekly -1 2026-11-02T13:00', 'a used percent is a number of zero or more'];
        yield 'a percent that is no number' => ['2026-11-02T08:00 read claude_code.main weekly lots 2026-11-02T13:00', 'a used percent is a number of zero or more'];
        yield 'an unknown hold' => ['2026-11-02T08:00 hold stop 2026-11-02T09:00', 'a hold is one of drain, failure-back-off, pause, budget-back-off'];
        yield 'calls running that are no whole number' => ['2026-11-02T08:00 start claude_code.main -1', 'whole number of zero or more'];
        yield 'a moment that rolls over' => ['2026-11-02T08:00 limit claude_code.main 2026-11-02T25:00', 'a moment is written as 2026-11-02T08:00 in UTC'];
        yield 'a moment with a zone' => ['2026-11-02T08:00+02:00 merge', 'a moment is written as 2026-11-02T08:00 in UTC'];
    }

    #[DataProvider('linesNotUnderstood')]
    public function testALineNotUnderstoodIsRefusedWithItsRule(string $line, string $rule): void
    {
        $this->expectException(ScriptLineNotUnderstood::class);
        $this->expectExceptionMessageMatches('/'.preg_quote($rule, '/').'/');

        $this->play($line);
    }

    private function play(string $line): string
    {
        return $this->replay->play(ScriptedStep::parse($line));
    }
}
