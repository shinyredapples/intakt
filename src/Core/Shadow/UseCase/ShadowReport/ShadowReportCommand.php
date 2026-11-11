<?php

declare(strict_types=1);

namespace Intakt\Core\Shadow\UseCase\ShadowReport;

use Intakt\Core\Shadow\Contract\HeadComparison;
use Intakt\Core\Shadow\Contract\ReviewerDecision;
use Intakt\Core\Shadow\Contract\ShadowSummary;
use Intakt\Core\Shadow\Domain\LoopReviewReader;
use Intakt\Core\Shadow\Domain\PeriodSummary;
use Intakt\Core\Shadow\Domain\ReviewComparison;
use Intakt\Core\Shadow\Infrastructure\RecordedFolder;
use Intakt\Core\Shadow\Infrastructure\RecordedHead;
use NoDiscard;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'intakt:shadow:report', description: 'Print the summary and the per-head table of the loop reviews compared with Intakt shadow reviews of a recorded period')]
final readonly class ShadowReportCommand
{
    public function __construct(
        private LoopReviewReader $reader,
        private ReviewComparison $comparison,
        private PeriodSummary $summary,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Folder of recorded heads, one JSON file per head (the intakt:shadow:compare-head format)')]
        string $folder,
        #[Option('Output format: table or json')]
        ReportFormat $format = ReportFormat::Table,
    ): int {
        $files = RecordedFolder::jsonFiles($folder);
        if (null === $files) {
            $io->error(sprintf('Cannot read the folder %s.', $folder));

            return Command::INVALID;
        }

        $comparisons = [];
        foreach ($files as $file) {
            $comparison = $this->compare($file);
            if (!$comparison instanceof HeadComparison) {
                $io->error(sprintf('Cannot read a recorded head from %s.', $file));

                return Command::INVALID;
            }
            $comparisons[] = $comparison;
        }

        $summary = $this->summary->summarize($comparisons);
        match ($format) {
            ReportFormat::Table => $this->printTable($io, $comparisons, $summary),
            ReportFormat::Json => $this->printJson($io, $comparisons, $summary),
        };

        return Command::SUCCESS;
    }

    private function compare(string $file): ?HeadComparison
    {
        $recorded = RecordedHead::fromFile($file);
        if (!$recorded instanceof RecordedHead) {
            return null;
        }

        $loop = $this->reader->read($recorded->identity, $recorded->head, $recorded->comments);

        return $this->comparison->compare($recorded->head, $loop, $recorded->shadowRounds);
    }

    /**
     * @param list<HeadComparison> $comparisons
     */
    private function printTable(SymfonyStyle $io, array $comparisons, ShadowSummary $summary): void
    {
        $io->table(
            ['head', 'loop', 'intakt', 'matched', 'loop only', 'intakt only', 'severity differs', 'intakt failed', 'loop unreadable', 'tokens', 'tokens per review'],
            array_map(fn (HeadComparison $head): array => [
                $head->head,
                $this->decisions($head->loopDecisions),
                $this->decisions($head->intaktDecisions),
                count($head->matches),
                count($head->loopOnly),
                count($head->intaktOnly),
                $head->severityDisagreements(),
                $head->intaktFailedReviews(),
                $head->unreadableLoopReviews(),
                $head->intaktTokens,
                $this->tokensPerReview($head->intaktTokens, $head->intaktReviews()) ?? '-',
            ], $comparisons),
        );

        $io->writeln(sprintf(
            '%d heads, %d matched, %d loop only, %d intakt only, %d severity disagreements, %d intakt failed reviews, %d unreadable loop reviews, %d intakt tokens in %d reviews (%s per review)',
            $summary->heads,
            $summary->matched,
            $summary->loopOnly,
            $summary->intaktOnly,
            $summary->severityDisagreements,
            $summary->intaktFailedReviews,
            $summary->unreadableLoopReviews,
            $summary->intaktTokens,
            $summary->intaktReviews,
            $this->tokensPerReview($summary->intaktTokens, $summary->intaktReviews) ?? '-',
        ), OutputInterface::OUTPUT_RAW);
    }

    /**
     * @param list<HeadComparison> $comparisons
     */
    private function printJson(SymfonyStyle $io, array $comparisons, ShadowSummary $summary): void
    {
        $report = [
            'summary' => [
                'heads' => $summary->heads,
                'matched' => $summary->matched,
                'loopOnly' => $summary->loopOnly,
                'intaktOnly' => $summary->intaktOnly,
                'severityDisagreements' => $summary->severityDisagreements,
                'intaktFailedReviews' => $summary->intaktFailedReviews,
                'unreadableLoopReviews' => $summary->unreadableLoopReviews,
                'intaktReviews' => $summary->intaktReviews,
                'intaktTokens' => $summary->intaktTokens,
                'tokensPerReview' => $this->tokensPerReview($summary->intaktTokens, $summary->intaktReviews),
            ],
            'heads' => array_map(fn (HeadComparison $head): array => [
                'head' => $head->head,
                'loopDecisions' => $this->decisionMap($head->loopDecisions),
                'intaktDecisions' => $this->decisionMap($head->intaktDecisions),
                'matched' => count($head->matches),
                'loopOnly' => count($head->loopOnly),
                'intaktOnly' => count($head->intaktOnly),
                'severityDisagreements' => $head->severityDisagreements(),
                'intaktFailedReviews' => $head->intaktFailedReviews(),
                'unreadableLoopReviews' => $head->unreadableLoopReviews(),
                'intaktReviews' => $head->intaktReviews(),
                'intaktTokens' => $head->intaktTokens,
                'tokensPerReview' => $this->tokensPerReview($head->intaktTokens, $head->intaktReviews()),
            ], $comparisons),
        ];

        $io->writeln(json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), OutputInterface::OUTPUT_RAW);
    }

    /**
     * @param list<ReviewerDecision> $decisions
     */
    private function decisions(array $decisions): string
    {
        return implode("\n", array_map(static fn (ReviewerDecision $d): string => sprintf('%s: %s', $d->reviewer, $d->outcome->value), $decisions));
    }

    /**
     * @param list<ReviewerDecision> $decisions
     *
     * @return list<array{reviewer: string, outcome: string}>
     */
    private function decisionMap(array $decisions): array
    {
        return array_map(static fn (ReviewerDecision $d): array => ['reviewer' => $d->reviewer, 'outcome' => $d->outcome->value], $decisions);
    }

    // A period without Intakt reviews has no average, and a divide by zero must not stand in for one.
    #[NoDiscard('an average that is not read reports nothing')]
    private function tokensPerReview(int $tokens, int $reviews): ?float
    {
        return 0 === $reviews ? null : round($tokens / $reviews, 2);
    }
}
