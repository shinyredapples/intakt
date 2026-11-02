<?php

declare(strict_types=1);

namespace Intakt\Core\Budget\UseCase\ReplayDay;

use Doctrine\ORM\EntityManagerInterface;
use Intakt\Core\Budget\Domain\BudgetGuard;
use Intakt\Core\Budget\Domain\CeilingStartGuard;
use Intakt\Core\Budget\Infrastructure\DoctrineHolds;
use Intakt\Core\Budget\Infrastructure\DoctrineMeasuredUse;
use Intakt\Core\Budget\Infrastructure\DoctrineOutageNotices;
use InvalidArgumentException;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;

#[AsCommand(name: 'intakt:budget:replay', description: 'Print the budget guard\'s decision at each step of a scripted day on this install\'s database, then roll every write back')]
final readonly class ReplayDayCommand
{
    /**
     * @param array<string, float> $ceilings
     */
    public function __construct(
        private EntityManagerInterface $entities,
        private LockFactory $locks,
        private Filesystem $files,
        #[Autowire('%intakt.budget.ceilings%')]
        private array $ceilings,
    ) {
    }

    public function __invoke(SymfonyStyle $io, #[Argument('The script: one step per line, a UTC moment such as 2026-11-02T08:00, then read, limit, hold, start or merge with its arguments')] string $script): int
    {
        try {
            $steps = $this->stepsOf($script);
        } catch (IOException|InvalidArgumentException $failure) {
            $io->error($failure->getMessage());

            return Command::FAILURE;
        }

        return $this->replayRolledBack($io, $steps);
    }

    /**
     * @return non-empty-list<ScriptedStep>
     */
    private function stepsOf(string $script): array
    {
        $lines = array_filter(
            preg_split('/\R/', $this->files->readFile($script)) ?: [],
            static fn (string $line): bool => '' !== trim($line) && !str_starts_with(trim($line), '#'),
        );
        $steps = array_values(array_map(ScriptedStep::parse(...), $lines));
        if ([] === $steps) {
            throw new InvalidArgumentException('The script holds no step.');
        }

        return $steps;
    }

    // Rolled back, so a replay shows what the guard would decide without leaving a reading, a hold or a notice on the install.
    /**
     * @param non-empty-list<ScriptedStep> $steps
     */
    private function replayRolledBack(SymfonyStyle $io, array $steps): int
    {
        $connection = $this->entities->getConnection();
        $connection->beginTransaction();

        try {
            return $this->replay($io, $steps);
        } finally {
            $connection->rollBack();
            $this->entities->clear();
        }
    }

    /**
     * @param non-empty-list<ScriptedStep> $steps
     */
    private function replay(SymfonyStyle $io, array $steps): int
    {
        $replay = $this->replayOn(new MockClock($steps[0]->at), new ConsoleLogger($io));

        foreach ($steps as $step) {
            try {
                $io->writeln(OutputFormatter::escape($step->line.': '.$replay->play($step)));
            } catch (InvalidArgumentException $failure) {
                $io->error($failure->getMessage());

                return Command::FAILURE;
            }
        }

        return Command::SUCCESS;
    }

    private function replayOn(MockClock $clock, ConsoleLogger $logger): DayReplay
    {
        $measured = new DoctrineMeasuredUse($this->entities, $clock);
        $holds = new DoctrineHolds($this->entities, $clock);
        $notices = new DoctrineOutageNotices($this->entities, $clock, $this->locks, $logger);
        $guard = new BudgetGuard($holds, new CeilingStartGuard($measured, $clock, $this->ceilings), $measured, $notices);

        return new DayReplay($clock, $measured, $holds, $guard);
    }
}
