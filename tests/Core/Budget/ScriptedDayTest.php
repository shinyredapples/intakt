<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Budget;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Budget\Contract\ReadingOutcome;
use Intakt\Core\Budget\Contract\StartDecision;
use Intakt\Core\Budget\Contract\Usage;
use Intakt\Core\Budget\Contract\UsageWindow;
use Intakt\Core\Budget\Contract\WindowReading;
use Intakt\Core\Budget\Contract\WindowUse;
use Intakt\Core\Budget\Domain\CeilingStartGuard;
use Intakt\Core\Budget\Domain\WindowReadingRecord;
use Intakt\Core\Budget\Infrastructure\DoctrineMeasuredUse;
use Intakt\Tests\Application\EmptyDatabase;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\EventDispatcher\EventDispatcher;

// Through the Doctrine store, because a worker decides on the readings it keeps, refusals and ages included.
#[Group('persistence')]
#[CoversClass(CeilingStartGuard::class)]
#[UsesClass(DoctrineMeasuredUse::class)]
#[UsesClass(WindowReadingRecord::class)]
#[UsesClass(StartDecision::class)]
#[UsesClass(WindowReading::class)]
#[UsesClass(WindowUse::class)]
#[UsesClass(Usage::class)]
#[UsesClass(UsageWindow::class)]
#[UsesClass(Account::class)]
final class ScriptedDayTest extends KernelTestCase
{
    private const string WEEK_RESET = '2026-11-08 08:00';

    private MockClock $clock;
    private DoctrineMeasuredUse $measured;
    private CeilingStartGuard $guard;

    public function testTheGuardDecidesEachStartOfAScriptedDayFromTheMeasuredUseOfItsOwnAccount(): void
    {
        $decisions = [];
        foreach ($this->day() as [$time, $readings, $callsRunning]) {
            $this->clock->modify('2026-11-02 '.$time.' UTC');
            $this->recordAll($readings);
            $decisions[] = $time.' with '.$callsRunning.' running: '.$this->guard->decide($this->main(), $callsRunning)->value;
        }

        self::assertSame([
            '08:00 with 0 running: start',
            '08:00 with 1 running: no-trusted-reading',
            '08:01 with 1 running: start',
            '08:10 with 1 running: reading-not-fresh-enough',
            '08:10 with 0 running: start',
            '09:30 with 1 running: no-margin-for-parallel-start',
            '10:00 with 0 running: at-ceiling',
            '12:59 with 0 running: at-ceiling',
            '13:00 with 0 running: start',
            '13:00 with 1 running: no-trusted-reading',
            '13:05 with 0 running: start',
            '14:00 with 0 running: at-ceiling',
            '23:00 with 1 running: start',
        ], $decisions);
    }

    protected function setUp(): void
    {
        $entities = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entities);
        EmptyDatabase::in(self::getContainer());
        new SchemaTool($entities)->createSchema($entities->getMetadataFactory()->getAllMetadata());
        $this->clock = new MockClock('2026-11-02 08:00:00 UTC');
        $this->measured = new DoctrineMeasuredUse($entities, $this->clock, new EventDispatcher());
        $this->guard = new CeilingStartGuard($this->measured, $this->clock, ['five-hour' => 90.0, 'weekly' => 90.0]);
    }

    /**
     * @return list<array{string, list<array{Account, UsageWindow, float, string}>, int}>
     */
    private function day(): array
    {
        return [
            ['08:00', [[$this->team(), UsageWindow::FiveHour, 99.0, '2026-11-02 13:00']], 0],
            ['08:00', [], 1],
            ['08:01', [[$this->main(), UsageWindow::FiveHour, 20.0, '2026-11-02 13:00'], [$this->main(), UsageWindow::Weekly, 10.0, self::WEEK_RESET]], 1],
            ['08:10', [], 1],
            ['08:10', [], 0],
            ['09:30', [[$this->main(), UsageWindow::FiveHour, 85.0, '2026-11-02 13:00'], [$this->main(), UsageWindow::Weekly, 12.0, self::WEEK_RESET]], 1],
            ['10:00', [[$this->main(), UsageWindow::FiveHour, 91.0, '2026-11-02 13:00']], 0],
            ['12:59', [], 0],
            ['13:00', [], 0],
            ['13:00', [], 1],
            // Weekly 40 percent is ahead of the pace of about 18.3 percent, so the five-hour ceiling drops to about 41 percent.
            ['13:05', [[$this->main(), UsageWindow::FiveHour, 30.0, '2026-11-02 18:00'], [$this->main(), UsageWindow::Weekly, 40.0, self::WEEK_RESET]], 0],
            ['14:00', [[$this->main(), UsageWindow::FiveHour, 43.0, '2026-11-02 18:00']], 0],
            ['23:00', [[$this->main(), UsageWindow::FiveHour, 5.0, '2026-11-03 03:00'], [$this->main(), UsageWindow::Weekly, 41.0, self::WEEK_RESET]], 1],
        ];
    }

    /**
     * @param list<array{Account, UsageWindow, float, string}> $readings
     */
    private function recordAll(array $readings): void
    {
        foreach ($readings as [$account, $window, $percent, $resetsAt]) {
            $moment = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $resetsAt, new DateTimeZone('UTC'));
            self::assertInstanceOf(DateTimeImmutable::class, $moment);
            $usage = new Usage($percent, $moment);
            self::assertSame(ReadingOutcome::Recorded, $this->measured->record(new WindowReading($account, $window, $usage)));
        }
    }

    private function main(): Account
    {
        return new Account(Provider::ClaudeCode, 'main');
    }

    private function team(): Account
    {
        return new Account(Provider::ClaudeCode, 'team');
    }
}
