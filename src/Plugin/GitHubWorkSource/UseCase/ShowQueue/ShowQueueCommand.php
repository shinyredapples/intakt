<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubWorkSource\UseCase\ShowQueue;

use Intakt\Plugin\WorkSource\Contract\Task;
use Intakt\Plugin\WorkSource\Contract\WorkQueue;
use Intakt\Plugin\WorkSource\Contract\WorkSource;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'intakt:queue:show', description: 'Print the order in which the work source would pick the tasks of a project, then the held tasks with their reasons')]
final readonly class ShowQueueCommand
{
    private const string UNICODE_CONTROLS = '/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]/u';

    public function __construct(
        private WorkSource $workSource,
    ) {
    }

    // The work source logs why a listing came back short, so the command only refuses to print an order computed over part of it.
    public function __invoke(SymfonyStyle $io, #[Argument('The project, as named in the configuration')] string $project): int
    {
        $queue = $this->workSource->queue($project);
        if (!$queue->complete) {
            $io->getErrorStyle()->error(sprintf('The listing of project %s came back short, so no order is shown; the log says why.', $this->printable($project)));

            return Command::FAILURE;
        }

        $this->printPickOrder($io, $project, $queue);
        $this->printHeldTasks($io, $project, $queue);

        return Command::SUCCESS;
    }

    private function printable(string $line): string
    {
        // Unicode's controls, format characters and line breaks too (CSI as U+009B, a next line, a direction override), the set ConsoleReport escapes; text that is not UTF-8 keeps only its printable ASCII, as BuildTaskCommand does, since its other bytes cannot be told from control characters.
        return preg_replace(self::UNICODE_CONTROLS, ' ', $line) ?? (string) preg_replace('/[^\x20-\x7E]/', '?', $line);
    }

    private function printPickOrder(SymfonyStyle $io, string $project, WorkQueue $queue): void
    {
        $this->printLine($io, sprintf('Pick order of project %s:', $project));
        if ([] === $queue->ready()) {
            $io->writeln('  no task is ready');

            return;
        }

        foreach ($queue->ready() as $position => $task) {
            $this->printLine($io, sprintf('  %d. %s', $position + 1, $this->lineOf($task)));
        }
    }

    private function printHeldTasks(SymfonyStyle $io, string $project, WorkQueue $queue): void
    {
        $this->printLine($io, sprintf('Held tasks of project %s:', $project));
        if ([] === $queue->held()) {
            $io->writeln('  no task is held');

            return;
        }

        foreach ($queue->held() as $held) {
            $this->printLine($io, sprintf('  %s: %s', $this->lineOf($held->task), $held->reason));
        }
    }

    // Raw and without control characters, because a title or a quoted wait line is issue text anyone can write, and it must not restyle or rewrite the terminal; the headings go raw too, so a project name is printed as configured.
    private function printLine(SymfonyStyle $io, string $line): void
    {
        $io->writeln($this->printable($line), OutputInterface::OUTPUT_RAW);
    }

    private function lineOf(Task $task): string
    {
        $line = sprintf('#%d %s', $task->number, $task->title);

        return null === $task->reworkOf ? $line : sprintf('%s (rework of #%d)', $line, $task->reworkOf);
    }
}
