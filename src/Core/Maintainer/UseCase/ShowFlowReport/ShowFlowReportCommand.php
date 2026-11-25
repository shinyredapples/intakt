<?php

declare(strict_types=1);

namespace Intakt\Core\Maintainer\UseCase\ShowFlowReport;

use DateTimeImmutable;
use Intakt\Core\Maintainer\Domain\FlowReport;
use Intakt\Core\Maintainer\Domain\HandOver;
use Intakt\Core\Maintainer\Domain\OverdueMilestone;
use Intakt\Core\Maintainer\Domain\PriorityShare;
use Intakt\Plugin\WorkSource\Contract\Holder;
use Intakt\Plugin\WorkSource\Contract\OpenWork;
use Intakt\Plugin\WorkSource\Contract\WorkSource;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'intakt:maintainer:flow-report', description: 'Print the flow figures of a project: hand-overs by what they hold, overdue milestones with open work and the priority mix')]
final readonly class ShowFlowReportCommand
{
    private const string UNICODE_CONTROLS = '/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]/u';
    // More than a third urgent, the default until the owner's config holds a value.
    private const float URGENT_THRESHOLD = 1 / 3;

    public function __construct(
        private WorkSource $workSource,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(SymfonyStyle $io, #[Argument('The project, as named in the configuration')] string $project): int
    {
        $work = $this->workSource->openWork($project);
        if (!$work instanceof OpenWork) {
            $io->getErrorStyle()->error(sprintf('The listing of project %s came back short, so no report is shown; the log says why.', $this->printable($project)));

            return Command::FAILURE;
        }

        $report = FlowReport::of($work, DateTimeImmutable::createFromInterface($this->clock->now()), self::URGENT_THRESHOLD);

        $this->printLine($io, sprintf('Flow report of project %s:', $project));
        $this->printHandOvers($io, $report);
        $this->printOverdueMilestones($io, $report);
        $this->printPriorityMix($io, $report);

        return Command::SUCCESS;
    }

    private function printHandOvers(SymfonyStyle $io, FlowReport $report): void
    {
        $this->printLine($io, 'Hand-overs by what they hold:');
        if ([] === $report->handOvers) {
            $io->writeln('  no task waits on a session or a person');
        }

        foreach ($report->handOvers as $handOver) {
            $this->printLine($io, sprintf('  %s', $this->handOverLine($handOver)));
        }
    }

    private function handOverLine(HandOver $handOver): string
    {
        $holder = Holder::Person === $handOver->task->holder ? 'a person' : 'a session';

        return sprintf('#%d %s: waits on %s, holds %d open tasks', $handOver->task->number, $handOver->task->title, $holder, $handOver->openTasksBehind);
    }

    private function printOverdueMilestones(SymfonyStyle $io, FlowReport $report): void
    {
        $this->printLine($io, 'Overdue milestones with open work:');
        if ([] === $report->overdueMilestones) {
            $io->writeln('  none');
        }

        foreach ($report->overdueMilestones as $milestone) {
            $this->printLine($io, sprintf('  %s: %d open tasks', $this->dayOf($milestone), $milestone->openTasks));
        }

        foreach ($report->milestonesToClose as $due) {
            $this->printLine($io, sprintf('  %s: no open task, one to close (the owner decides)', $due->format('Y-m-d')));
        }
    }

    private function dayOf(OverdueMilestone $milestone): string
    {
        return 'due '.$milestone->due->format('Y-m-d');
    }

    private function printPriorityMix(SymfonyStyle $io, FlowReport $report): void
    {
        $this->printLine($io, 'Priority mix of the open tasks:');
        if ([] === $report->priorityMix) {
            $io->writeln('  no open task');
        }

        foreach ($report->priorityMix as $share) {
            $this->printLine($io, $this->shareLine($share));
        }

        if ($report->tooManyUrgent) {
            $io->writeln('  finding for the session: more than a third of the open tasks are urgent');
        }
    }

    private function shareLine(PriorityShare $share): string
    {
        return sprintf('  %s: %d (%d%%)', $share->priority->value, $share->openTasks, round($share->share * 100));
    }

    private function printable(string $line): string
    {
        // Unicode's controls, format characters and line breaks too, the set ShowQueueCommand strips; text that is not UTF-8 keeps only its printable ASCII.
        return preg_replace(self::UNICODE_CONTROLS, ' ', $line) ?? (string) preg_replace('/[^\x20-\x7E]/', '?', $line);
    }

    // Raw and without control characters, because a title is issue text anyone can write and must not restyle or rewrite the terminal.
    private function printLine(SymfonyStyle $io, string $line): void
    {
        $io->writeln($this->printable($line), OutputInterface::OUTPUT_RAW);
    }
}
