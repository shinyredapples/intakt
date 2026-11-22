<?php

declare(strict_types=1);

namespace Intakt\Core\Budget\UseCase\ReplayDay;

use Closure;
use Doctrine\ORM\EntityManagerInterface;
use Intakt\Core\Budget\Contract\BudgetServices;
use Intakt\Core\Budget\Domain\BudgetGuard;
use Intakt\Core\Budget\Domain\CeilingStartGuard;
use Intakt\Core\Budget\Domain\OutageWatch;
use Intakt\Core\Budget\Domain\UsageReadingsStayedAway;
use Intakt\Core\Budget\Infrastructure\DoctrineHolds;
use Intakt\Core\Budget\Infrastructure\DoctrineMeasuredUse;
use Intakt\Core\Budget\Infrastructure\DoctrineOutageNotices;
use Intakt\Core\Watchdog\Contract\Halts;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Lock\LockFactory;

// Rolled back, so a replay shows what the guard would decide without leaving a reading, a hold or a notice on the install.
final readonly class RolledBackReplay
{
    /**
     * @param array<string, float> $ceilings
     */
    public function __construct(
        private EntityManagerInterface $entities,
        private LockFactory $locks,
        #[Autowire(param: BudgetServices::CEILINGS)]
        private array $ceilings,
        private Halts $halts,
    ) {
    }

    /**
     * @param non-empty-list<ScriptedStep> $steps
     * @param Closure(string): void        $print
     *
     * @throws ScriptLineNotUnderstood
     */
    public function replay(array $steps, LoggerInterface $logger, Closure $print): void
    {
        $clock = new MockClock($steps[0]->at);
        $day = $this->dayReplayOn($clock, $logger);
        $connection = $this->entities->getConnection();
        $connection->beginTransaction();

        try {
            foreach ($steps as $step) {
                $this->advance($clock, $step);
                $print($step->line.': '.$day->play($step));
            }
        } finally {
            $connection->rollBack();
            $this->entities->clear();
        }
    }

    private function dayReplayOn(MockClock $clock, LoggerInterface $logger): DayReplay
    {
        $events = new EventDispatcher();
        $measured = new DoctrineMeasuredUse($this->entities, $clock, $events);
        $holds = new DoctrineHolds($this->entities, $clock);
        $events->addListener(UsageReadingsStayedAway::class, new DoctrineOutageNotices($this->entities, $this->locks, $logger, $events)->noticeOnce(...));
        $guard = new BudgetGuard($holds, new CeilingStartGuard($measured, $clock, $this->ceilings), new OutageWatch($measured, $clock, $events), $this->halts);

        return new DayReplay($measured, $holds, $guard);
    }

    // The clock never steps back, so a reading's age and every reset are what the day would have shown.
    private function advance(MockClock $clock, ScriptedStep $step): void
    {
        $seconds = $step->at->getTimestamp() - $clock->now()->getTimestamp();
        if ($seconds < 0) {
            throw ScriptLineNotUnderstood::because($step->line, 'the steps must be in time order');
        }

        $clock->sleep($seconds);
    }
}
