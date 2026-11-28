<?php

declare(strict_types=1);

namespace Intakt\Core\Build\UseCase\RunBuildJob;

use Intakt\Core\Build\Domain\BuildObservation;
use Intakt\Core\Build\Domain\TrustedBuildFigures;
use Intakt\Core\Build\Domain\UntrustedBuildFigures;
use Intakt\Core\Job\Contract\JobOutput;
use JsonException;
use NoDiscard;
use Psr\Clock\ClockInterface;
use Symfony\AI\Platform\Bridge\ClaudeCode\TokenUsageExtractor;
use Symfony\AI\Platform\Result\InMemoryRawResult;

// Reads the figures from the job's output, because the job runner hands nothing back before the job ends: the session's failing tests and running checks never reach that output.
final readonly class BuildJobObserver
{
    private const array COUNTS = ['input_tokens', 'output_tokens', 'cache_creation_input_tokens', 'cache_read_input_tokens'];

    private const int JSON_DEPTH = 64;

    private const string TIP = '/^(?:[0-9a-f]{40}|[0-9a-f]{64}) \S+$/';

    public function __construct(private ClockInterface $clock)
    {
    }

    #[NoDiscard]
    public function observe(JobOutput $output): BuildObservation
    {
        return new BuildObservation(
            new TrustedBuildFigures($this->tokensIn($output->contents(RunBuildJob::LOG) ?? ''), $this->clock->now()),
            // No failing tests and no running check, because the job has ended and its output names neither.
            new UntrustedBuildFigures($this->contentOf($output->contents(RunBuildJob::BUNDLE)), [], false),
        );
    }

    // The largest report, because the builder can add lines to the log too, and a forged report may then only stop the build sooner.
    private function tokensIn(string $log): int
    {
        return max(0, ...array_map($this->reportedTokens(...), explode("\n", $log)));
    }

    // The tips the bundle names, because the commits' content can only be read with git, which the hand-back check runs on the bundle later.
    private function contentOf(?string $bundle): string
    {
        $header = explode("\n\n", $bundle ?? '', 2)[0];
        $tips = array_values(array_filter(explode("\n", $header), static fn (string $line): bool => 1 === preg_match(self::TIP, $line)));

        if ([] === $tips) {
            return '';
        }

        sort($tips, SORT_STRING);

        return hash('sha256', implode("\n", $tips));
    }

    private function reportedTokens(string $line): int
    {
        try {
            $event = json_decode($line, true, self::JSON_DEPTH, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return 0;
        }

        if (!is_array($event) || 'result' !== ($event['type'] ?? null) || !$this->countsAreWhole($event['usage'] ?? null)) {
            return 0;
        }

        $usage = new TokenUsageExtractor()->extract(new InMemoryRawResult($event));
        $counts = [(int) $usage?->getPromptTokens(), (int) $usage?->getCompletionTokens(), (int) $usage?->getCachedTokens()];

        // Capped, because a forged report can pass the largest integer, and the sum would then become a float.
        return (float) $counts[0] + $counts[1] + $counts[2] >= PHP_INT_MAX ? PHP_INT_MAX : $counts[0] + $counts[1] + $counts[2];
    }

    // Because the extractor hands a count of another type on unchecked, which would stop it with a TypeError.
    private function countsAreWhole(mixed $usage): bool
    {
        return is_array($usage) && array_all(array_intersect_key($usage, array_flip(self::COUNTS)), static fn (mixed $count): bool => is_int($count) && $count >= 0);
    }
}
