<?php

declare(strict_types=1);

namespace Intakt\Core\Build\Domain;

use Intakt\Core\HandBack\Contract\RegressionRuns;

// Written by Intakt into the pull request after its text check, because the reviewers must see the runs Intakt measured and not the builder's claim.
final readonly class RegressionEvidence
{
    private const string HEADING = '## Evidence';

    public function __construct(
        private RegressionRuns $runs,
    ) {
    }

    public function addedTo(string $body): string
    {
        $body = $this->closed(rtrim(ReadableLines::withLineFeeds($body)));
        $lines = explode("\n", $body);
        $readable = ReadableLines::of($body)->lines;
        $heading = $this->headingIn($readable);

        if (null === $heading) {
            return ltrim($body."\n\n".self::HEADING."\n\n".$this->resultLines());
        }

        $end = $this->sectionEndIn($readable, $heading + 1);
        $section = rtrim(implode("\n", array_slice($lines, 0, $end)))."\n".$this->resultLines();
        $rest = array_slice($lines, $end);

        return [] === $rest ? $section : $section."\n\n".implode("\n", $rest);
    }

    // A fence or comment the body never closes is closed first, because otherwise it would hold every line Intakt adds after it.
    private function closed(string $body): string
    {
        $closer = ReadableLines::of($body)->closer;

        return null === $closer ? $body : $body."\n".$closer;
    }

    /**
     * @param list<string> $readable
     */
    private function headingIn(array $readable): ?int
    {
        $found = array_find_key($readable, static fn (string $line): bool => self::HEADING === rtrim($line));

        return is_int($found) ? $found : null;
    }

    private function resultLines(): string
    {
        $tests = implode(', ', array_map($this->codeSpanOf(...), [...$this->runs->tests]));

        return sprintf(
            "- Regression tests, run by Intakt: %s\n- Regression result: %s on the base, %s on the head",
            $tests,
            $this->runs->base->value ?? 'no run',
            $this->runs->head->value ?? 'no run',
        );
    }

    // Control characters and backticks replaced, because a test path comes from the model-written diff and must not end its code span or start a line of its own.
    private function codeSpanOf(string $path): string
    {
        return '`'.preg_replace('/[\x00-\x1F\x7F`]/', '?', $path).'`';
    }

    // A third-level heading stays inside, because builders number their Evidence items as subheadings too.
    /**
     * @param list<string> $readable
     */
    private function sectionEndIn(array $readable, int $from): int
    {
        foreach (array_slice($readable, $from, preserve_keys: true) as $index => $line) {
            if (1 === preg_match('/^#{1,2}\s/', $line)) {
                return $index;
            }
        }

        return count($readable);
    }
}
