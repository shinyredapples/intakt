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
        $lines = explode("\n", rtrim($body));
        $heading = $this->headingIn($lines, 0);

        if (null === $heading) {
            return ltrim(implode("\n", $lines)."\n\n".self::HEADING."\n\n".$this->resultLines());
        }

        $end = $this->sectionEndIn($lines, $heading + 1);
        $section = rtrim(implode("\n", array_slice($lines, 0, $end)))."\n".$this->resultLines();
        $rest = array_slice($lines, $end);

        return [] === $rest ? $section : $section."\n\n".implode("\n", $rest);
    }

    /**
     * @param list<string> $lines
     */
    private function headingIn(array $lines, int $from): ?int
    {
        foreach ($this->linesOutsideFences($lines, $from) as $index => $line) {
            if (self::HEADING === rtrim($line)) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param list<string> $lines
     *
     * @return iterable<int, string>
     */
    private function linesOutsideFences(array $lines, int $from): iterable
    {
        $fenced = false;

        foreach (array_slice($lines, $from, preserve_keys: true) as $index => $line) {
            if (1 === preg_match('/^ {0,3}(```|~~~)/', $line)) {
                $fenced = !$fenced;

                continue;
            }

            if (!$fenced) {
                yield $index => $line;
            }
        }
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
     * @param list<string> $lines
     */
    private function sectionEndIn(array $lines, int $from): int
    {
        foreach ($this->linesOutsideFences($lines, $from) as $index => $line) {
            if (1 === preg_match('/^#{1,2}\s/', $line)) {
                return $index;
            }
        }

        return count($lines);
    }
}
