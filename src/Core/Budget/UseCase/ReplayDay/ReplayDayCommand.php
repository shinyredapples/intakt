<?php

declare(strict_types=1);

namespace Intakt\Core\Budget\UseCase\ReplayDay;

use Intakt\Core\Budget\Contract\UsedPercentOutOfRange;
use Intakt\Core\Platform\Contract\ReportableFailure;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;

#[AsCommand(name: 'intakt:budget:replay', description: 'Print the budget guard\'s decision at each step of a scripted day on this install\'s database, then roll every write back')]
final readonly class ReplayDayCommand
{
    public function __construct(
        private RolledBackReplay $replay,
        private Filesystem $files,
    ) {
    }

    public function __invoke(SymfonyStyle $io, #[Argument('The script: one step per line, a UTC moment such as 2026-11-02T08:00, then read, limit, hold, start, merge or rank with its arguments')] string $script): int
    {
        try {
            $this->replay->replay($this->stepsOf($script), new ConsoleLogger($io), static fn (string $line) => $io->writeln(OutputFormatter::escape($line)));
        } catch (IOException|ReportableFailure|UsedPercentOutOfRange $failure) {
            $io->error($failure->getMessage());

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * @return non-empty-list<ScriptedStep>
     */
    private function stepsOf(string $script): array
    {
        $lines = preg_split('/\R/', $this->files->readFile($script));
        assert(is_array($lines));
        $lines = array_filter(
            $lines,
            static fn (string $line): bool => '' !== trim($line) && !str_starts_with(trim($line), '#'),
        );
        $steps = array_values(array_map(ScriptedStep::parse(...), $lines));
        if ([] === $steps) {
            throw ScriptHoldsNoStep::create();
        }

        return $steps;
    }
}
