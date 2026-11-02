<?php

declare(strict_types=1);

namespace Intakt\Core\Shadow\UseCase\CompareHead;

use Intakt\Core\Shadow\Contract\HeadComparison;
use Intakt\Core\Shadow\Contract\ReviewerDecision;
use Intakt\Core\Shadow\Domain\LoopReviewReader;
use Intakt\Core\Shadow\Domain\ReviewComparison;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'intakt:shadow:compare-head', description: 'Print the comparison of the loop reviews with Intakt shadow reviews of one recorded head')]
final readonly class CompareHeadCommand
{
    public function __construct(
        private LoopReviewReader $reader,
        private ReviewComparison $comparison,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('JSON file with "identity", "head", "comments" (author, head, body) and "intakt" (recorded shadow rounds)')]
        string $file,
    ): int {
        $recorded = RecordedHead::fromFile($file);
        if (null === $recorded) {
            $io->error(sprintf('Cannot read a recorded head from %s.', $file));

            return Command::INVALID;
        }

        $loop = $this->reader->read($recorded->identity, $recorded->head, $recorded->comments);
        $this->print($io, $this->comparison->compare($recorded->head, $loop, $recorded->shadowRounds));

        return Command::SUCCESS;
    }

    private function print(SymfonyStyle $io, HeadComparison $comparison): void
    {
        $lines = ['head '.$comparison->head];
        array_push($lines, ...array_map(static fn (ReviewerDecision $d): string => sprintf('loop %s: %s', $d->reviewer, $d->outcome->value), $comparison->loopDecisions));
        array_push($lines, ...array_map(static fn (ReviewerDecision $d): string => sprintf('intakt %s: %s', $d->reviewer, $d->outcome->value), $comparison->intaktDecisions));
        foreach ($comparison->matches as $match) {
            $lines[] = sprintf('matched %s:%d loop %s, intakt %s%s', $match->loop->file, $match->loop->line, $match->loop->severity->value, $match->intakt->severity->value, $match->disagreesOnSeverity() ? ' (severity differs)' : '');
        }
        foreach ($comparison->loopOnly as $finding) {
            $lines[] = sprintf('loop only %s:%d %s', $finding->file, $finding->line, $finding->severity->value);
        }
        foreach ($comparison->intaktOnly as $finding) {
            $lines[] = sprintf('intakt only %s:%s %s', $finding->file ?? '-', $finding->line ?? '-', $finding->severity->value);
        }
        $lines[] = sprintf(
            '%d matched, %d loop only, %d intakt only, %d severity disagreements, %d intakt failed reviews, %d unreadable loop reviews, %d intakt tokens in %d reviews',
            count($comparison->matches),
            count($comparison->loopOnly),
            count($comparison->intaktOnly),
            $comparison->severityDisagreements(),
            $comparison->intaktFailedReviews(),
            $comparison->unreadableLoopReviews(),
            $comparison->intaktTokens,
            $comparison->intaktReviews(),
        );

        foreach ($lines as $line) {
            $io->writeln($line, OutputInterface::OUTPUT_RAW);
        }
    }
}
