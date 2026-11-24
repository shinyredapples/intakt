<?php

declare(strict_types=1);

namespace Intakt\Core\Build\Domain;

use Intakt\Core\HandBack\Contract\RegressionRuns;

// Written by Intakt into the pull request after its text check, because the reviewers must see the runs Intakt measured and not the builder's claim.
final readonly class RegressionEvidence
{
    private const string HEADING = '## Evidence';

    // Up to three spaces in and with closing hashes too, because CommonMark reads both as the same heading.
    private const string EVIDENCE = '/^ {0,3}##[ \t]+Evidence(?:[ \t]+#+)?[ \t]*$/';

    // A third-level heading stays inside, because builders number their Evidence items as subheadings too.
    private const string SECTION_ENDS = '/^ {0,3}#{1,2}(?:[ \t]|$)/';

    public function __construct(
        private RegressionRuns $runs,
    ) {
    }

    public function addedTo(string $body): string
    {
        $body = rtrim(ReadableLines::withLineFeeds($body));
        $lines = explode("\n", $body);
        $read = ReadableLines::of($body);
        $heading = $this->firstTopLevel(self::EVIDENCE, $read, 0);

        if (null === $heading) {
            return ltrim(rtrim($this->closed($body))."\n\n".self::HEADING."\n\n".$this->resultLines());
        }

        $end = $this->firstTopLevel(self::SECTION_ENDS, $read, $heading + 1) ?? count($lines);
        $section = $this->closed(rtrim(implode("\n", array_slice($lines, 0, $end))))."\n".$this->resultLines();
        $rest = array_slice($lines, $end);

        return [] === $rest ? $section : $section."\n\n".implode("\n", $rest);
    }

    // A block the text never closes is closed first, because otherwise it would hold every line Intakt adds after it; an HTML block's empty closer leaves the blank line that ends it.
    private function closed(string $text): string
    {
        $closer = ReadableLines::of($text)->closer;

        return null === $closer ? $text : $text."\n".$closer;
    }

    // Only outside list items, because a heading inside one does not end the section.
    private function firstTopLevel(string $pattern, ReadableLines $read, int $from): ?int
    {
        $found = array_find_key(
            array_slice($read->lines, $from, preserve_keys: true),
            static fn (string $line, int $index): bool => 0 === $read->columns[$index] && 1 === preg_match($pattern, $line),
        );

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
}
