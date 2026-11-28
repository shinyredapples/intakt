<?php

declare(strict_types=1);

namespace Intakt\Core\Build\Domain;

use Intakt\Core\HandBack\Contract\RegressionRun;
use Intakt\Core\HandBack\Contract\RegressionRuns;

// Fixed words and the test paths read from the diff, never the builder's text or a log, because the body goes to the forge as written; ReadableLines finds the headings (it skips those inside fences, indented code and HTML blocks) and what is still open where the results go, and the body is otherwise left alone.
final readonly class RegressionEvidence
{
    private const string SAFE_PATH = '#^[A-Za-z0-9_./@+ -]+$#';

    public function __construct(
        private RegressionRuns $runs,
    ) {
    }

    public function addedTo(string $body): string
    {
        // CRLF and bare CR end lines in Markdown too, so a fence or heading split only on LF would be missed.
        $body = str_replace(["\r\n", "\r"], "\n", $body);
        $lines = explode("\n", $body);
        $readable = ReadableLines::of($lines);
        $headings = $readable->headings;
        $evidence = $this->evidenceIn($headings);

        if (null === $evidence) {
            return $this->joined($this->closed($body, $readable->closing()), "## Evidence\n\n".$this->resultLines(), '');
        }

        $end = $this->endOf($evidence, $headings, count($lines));
        $kept = array_slice($lines, 0, $end);

        // The lines up to the section's end are read again, because an element left open there would hide the results even when the body closes it later.
        return $this->joined($this->closed(implode("\n", $kept), ReadableLines::of($kept)->closing()), $this->resultLines(), implode("\n", array_slice($lines, $end)));
    }

    // Only line breaks go before a closer, because the trailing spaces of the last line are content of the code or block it closes.
    private function closed(string $text, string $closing): string
    {
        return '' === $closing ? rtrim($text) : rtrim($text, "\n").$closing;
    }

    /**
     * @param array<int, array{int, string}> $headings
     */
    private function evidenceIn(array $headings): ?int
    {
        foreach ($headings as $number => [, $text]) {
            // The colon form is the one the hand-back's Evidence reader also accepts, so it must not get a second section.
            if (1 === preg_match('/^evidence(?::|\s|$)/i', $text)) {
                return $number;
            }
        }

        return null;
    }

    private function resultLines(): string
    {
        return implode("\n", [
            '- Regression tests: '.$this->testsText(),
            '- On the base: '.$this->resultText($this->runs->base),
            '- On the head: '.$this->resultText($this->runs->head),
        ]);
    }

    private function joined(string $head, string $added, string $rest): string
    {
        $text = '' === $head ? $added : $head."\n\n".$added;

        return '' === trim($rest) ? $text : $text."\n\n".ltrim($rest, "\n");
    }

    private function testsText(): string
    {
        $paths = [];

        foreach ($this->runs->tests as $path) {
            $paths[] = 1 === preg_match(self::SAFE_PATH, $path) ? '`'.$path.'`' : 'a test whose path is not shown';
        }

        return implode(', ', $paths);
    }

    private function resultText(?RegressionRun $run): string
    {
        return $run->value ?? 'not run';
    }

    /**
     * @param array<int, array{int, string}> $headings
     */
    private function endOf(int $evidence, array $headings, int $lineCount): int
    {
        // A subheading such as `### E1` belongs to the section, so only a heading of the same or a higher level ends it.
        foreach ($headings as $number => [$level]) {
            if ($number > $evidence && $level <= $headings[$evidence][0]) {
                return $number;
            }
        }

        return $lineCount;
    }
}
