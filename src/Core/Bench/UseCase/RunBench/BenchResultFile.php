<?php

declare(strict_types=1);

namespace Intakt\Core\Bench\UseCase\RunBench;

use DateTimeImmutable;
use DateTimeZone;
use Intakt\Core\Bench\Contract\BenchReview;
use Intakt\Core\Bench\Contract\BenchReviewerResult;
use Intakt\Core\Bench\Contract\RepeatedChange;
use Intakt\Core\Bench\Domain\FailureCause;
use Intakt\Core\Bench\UseCase\ReviewBenchChange\ReadBenchProvenance;
use Intakt\Core\Review\Contract\Finding;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

/**
 * @phpstan-type WrittenFinding array{id: string, severity: string, file: ?string, line: ?int, text: string}
 * @phpstan-type WrittenReviewer array{role: string, tier: string, models: list<string>, caught: list<string>, missed: list<array{id: string, cause: string}>, false_alarms: list<WrittenFinding>, unmatched: list<WrittenFinding>, harness_failure: ?string, tokens: array{calls: int, prompt: int, completion: int, total: int}, milliseconds: ?int}
 * @phpstan-type WrittenRun array{decision: string, reviewers: list<WrittenReviewer>, act_list: list<WrittenFinding>}
 * @phpstan-type WrittenChange array{change: string, agreed: ?bool, runs: list<WrittenRun>}
 * @phpstan-type ResultDocument array{intakt_version: string, ran_at: string, project: string, prompts: array{reviewer: string, judge: string}, bench_repository: string, changes: list<WrittenChange>}
 */
final readonly class BenchResultFile
{
    public function __construct(
        private ReadBenchProvenance $provenance,
        private ClockInterface $clock,
        #[Autowire('%kernel.project_dir%/var/bench')]
        private string $directory,
        private Filesystem $filesystem = new Filesystem(),
    ) {
    }

    /**
     * @param list<RepeatedChange> $changes
     */
    public function write(string $project, array $changes): string
    {
        $ranAt = $this->clock->now()->setTimezone(new DateTimeZone('UTC'));
        $path = $this->pathFor($ranAt);
        $this->filesystem->dumpFile($path, json_encode($this->documentOf($project, $ranAt, $changes), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");

        return $path;
    }

    // A random part beside the moment, because two runs in the same second must not overwrite each other.
    private function pathFor(DateTimeImmutable $ranAt): string
    {
        return $this->directory.'/'.$ranAt->format('Ymd\THis\Z').'-'.bin2hex(random_bytes(3)).'.json';
    }

    /**
     * @param list<RepeatedChange> $changes
     *
     * @return ResultDocument
     */
    private function documentOf(string $project, DateTimeImmutable $ranAt, array $changes): array
    {
        $provenance = $this->provenance->read();

        return [
            'intakt_version' => $provenance->intaktVersion,
            'ran_at' => $ranAt->format(DATE_ATOM),
            'project' => $project,
            'prompts' => ['reviewer' => $provenance->prompts->reviewer, 'judge' => $provenance->prompts->judge],
            'bench_repository' => $provenance->benchRepositoryHash,
            'changes' => array_map($this->changeOf(...), $changes),
        ];
    }

    /**
     * @return WrittenChange
     */
    private function changeOf(RepeatedChange $change): array
    {
        return [
            'change' => $change->change,
            'agreed' => $change->agreed(),
            'runs' => array_map($this->runOf(...), $change->runs),
        ];
    }

    /**
     * @return WrittenRun
     */
    private function runOf(BenchReview $review): array
    {
        return [
            'decision' => $review->decision->value,
            'reviewers' => array_map($this->reviewerOf(...), $review->reviewers),
            'act_list' => array_map($this->findingOf(...), $review->actList),
        ];
    }

    /**
     * @return WrittenReviewer
     */
    private function reviewerOf(BenchReviewerResult $reviewer): array
    {
        $verdict = $reviewer->verdict;

        return [
            'role' => $reviewer->role,
            'tier' => $reviewer->tier->value,
            'models' => [...$reviewer->models],
            'caught' => $verdict->caught,
            // A list of pairs, because an empty map would be written as a JSON list.
            'missed' => array_map(static fn (string $id, FailureCause $cause): array => ['id' => $id, 'cause' => $cause->value], array_keys($verdict->missed), $verdict->missed),
            'false_alarms' => array_map($this->findingOf(...), $verdict->falseAlarms),
            'unmatched' => array_map($this->findingOf(...), $verdict->unmatched),
            'harness_failure' => $verdict->harnessFailure,
            'tokens' => ['calls' => $reviewer->tokens->calls, 'prompt' => $reviewer->tokens->prompt, 'completion' => $reviewer->tokens->completion, 'total' => $reviewer->tokens->total],
            'milliseconds' => $reviewer->milliseconds,
        ];
    }

    /**
     * @return WrittenFinding
     */
    private function findingOf(Finding $finding): array
    {
        return [
            'id' => $finding->id,
            'severity' => $finding->severity->value,
            'file' => $finding->file,
            'line' => $finding->line,
            'text' => $finding->text,
        ];
    }
}
