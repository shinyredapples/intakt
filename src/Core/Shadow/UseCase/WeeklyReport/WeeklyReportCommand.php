<?php

declare(strict_types=1);

namespace Intakt\Core\Shadow\UseCase\WeeklyReport;

use DateTimeImmutable;
use DateTimeZone;
use Intakt\Core\Shadow\Contract\Agreement;
use Intakt\Core\Shadow\Contract\LoopFinding;
use Intakt\Core\Shadow\Contract\PublishedHead;
use Intakt\Core\Shadow\Contract\ReviewerDecision;
use Intakt\Core\Shadow\Contract\WeeklyReport;
use Intakt\Core\Shadow\Domain\PublishedComparison;
use Intakt\Core\Shadow\Domain\RecordedPullRequest;
use Intakt\Core\Shadow\Domain\Week;
use Intakt\Core\Shadow\Infrastructure\RecordedFolder;
use Intakt\Core\Shadow\Infrastructure\RecordedMoment;
use Intakt\Core\Shadow\Infrastructure\RecordedPullRequestFile;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'intakt:shadow:weekly-report', description: 'Print the week\'s comparison of the loop\'s reviews and Intakt\'s published reviews on the same heads of recorded pull requests')]
final readonly class WeeklyReportCommand
{
    public function __construct(
        private PublishedComparison $comparison,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Folder of recorded pull requests, one JSON file per pull request')]
        string $folder,
        #[Option('End of the week as a moment with offset, for example 2026-11-09T00:00:00Z; the week is the seven days before it (default: now)')]
        ?string $until = null,
    ): int {
        $end = null === $until ? $this->clock->now()->setTimezone(new DateTimeZone('UTC')) : RecordedMoment::parse($until);
        if (!$end instanceof DateTimeImmutable) {
            $io->error(sprintf('Cannot read the end of the week %s.', $until));

            return Command::INVALID;
        }

        $pullRequests = $this->pullRequests($io, $folder);
        if (null === $pullRequests) {
            return Command::INVALID;
        }

        $this->print($io, $this->comparison->report($pullRequests, Week::endingAt($end)));

        return Command::SUCCESS;
    }

    /**
     * @return list<RecordedPullRequest>|null
     */
    private function pullRequests(SymfonyStyle $io, string $folder): ?array
    {
        $files = RecordedFolder::jsonFiles($folder);
        if (null === $files) {
            $io->error(sprintf('Cannot read the folder %s.', $folder));

            return null;
        }

        $pullRequests = [];
        foreach ($files as $file) {
            $pullRequest = RecordedPullRequestFile::read($file);
            if (!$pullRequest instanceof RecordedPullRequest) {
                $io->error(sprintf('Cannot read a recorded pull request from %s.', $file));

                return null;
            }
            $pullRequests[] = $pullRequest;
        }

        return $pullRequests;
    }

    private function print(SymfonyStyle $io, WeeklyReport $report): void
    {
        $io->writeln(sprintf('Week from %s to %s', $report->from->format(RecordedMoment::FORMAT), $report->until->format(RecordedMoment::FORMAT)), OutputInterface::OUTPUT_RAW);
        $io->table(
            ['pull request', 'head', 'loop', 'intakt', 'agreement', 'matched', 'loop only', 'intakt only'],
            array_map(fn (PublishedHead $head): array => [
                $head->pullRequest,
                $head->head,
                $this->decisions($head->loopDecisions),
                $this->decisions($head->intaktDecisions),
                $head->agreement->value,
                $head->matched,
                $this->findings($head->loopOnly),
                $this->findings($head->intaktOnly),
            ], $report->heads),
        );

        $io->writeln(sprintf(
            '%d heads: %d agree, %d disagree, %d incomplete; findings: %d matched, %d loop only, %d intakt only',
            count($report->heads),
            $report->withAgreement(Agreement::Agree),
            $report->withAgreement(Agreement::Disagree),
            $report->withAgreement(Agreement::Incomplete),
            $report->matched(),
            $report->loopOnly(),
            $report->intaktOnly(),
        ), OutputInterface::OUTPUT_RAW);
    }

    /**
     * @param list<ReviewerDecision> $decisions
     */
    private function decisions(array $decisions): string
    {
        return implode("\n", array_map(static fn (ReviewerDecision $decision): string => sprintf('%s: %s', $decision->reviewer, $decision->outcome->value), $decisions));
    }

    /**
     * @param list<LoopFinding> $findings
     */
    private function findings(array $findings): string
    {
        return implode("\n", array_map(static fn (LoopFinding $finding): string => sprintf('%s %s:%d', $finding->severity->value, $finding->file, $finding->line), $findings));
    }
}
