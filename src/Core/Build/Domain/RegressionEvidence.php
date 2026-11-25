<?php

declare(strict_types=1);

namespace Intakt\Core\Build\Domain;

use Intakt\Core\HandBack\Contract\RegressionRun;
use Intakt\Core\HandBack\Contract\RegressionRuns;

// Fixed words and the test paths read from the diff, never the builder's text or a log, because the body goes to the forge as written; only code fences and ATX headings are read, so other Markdown is left alone.
final readonly class RegressionEvidence
{
    private const string SAFE_PATH = '#^[A-Za-z0-9_./@+ -]+$#';

    public function __construct(
        private RegressionRuns $runs,
    ) {
    }

    public function addedTo(string $body): string
    {
        $lines = explode("\n", $body);
        [$headings, $open] = $this->scanned($lines);
        $evidence = $this->evidenceIn($headings);
        $closing = null === $open ? '' : "\n".$open;

        if (null === $evidence) {
            return $this->joined(rtrim($body.$closing), "## Evidence\n\n".$this->resultLines(), '');
        }

        $end = $this->endOf($evidence, $headings, count($lines));
        $kept = implode("\n", array_slice($lines, 0, $end));

        return $this->joined(rtrim($kept.($end === count($lines) ? $closing : '')), $this->resultLines(), implode("\n", array_slice($lines, $end)));
    }

    /**
     * @param list<string> $lines
     *
     * @return array{0: array<int, string>, 1: ?string}
     */
    private function scanned(array $lines): array
    {
        $headings = [];
        $fence = null;

        foreach ($lines as $number => $line) {
            if (null !== $fence) {
                $fence = $this->closes($line, $fence) ? null : $fence;

                continue;
            }

            $fence = $this->opening($line);

            if (null === $fence && 1 === preg_match('/^ {0,3}#{1,6}(?:[ \t]+(.*?))?(?:[ \t]+#+)?[ \t]*$/', $line, $heading)) {
                $headings[$number] = $heading[1] ?? '';
            }
        }

        return [$headings, $fence];
    }

    // A fence closes only on the same character repeated at least as often as the opening line, because any other line is still code.
    private function closes(string $line, string $fence): bool
    {
        return 1 === preg_match('/^ {0,3}('.preg_quote($fence[0], '/').'+)[ \t]*$/', $line, $match) && strlen($match[1]) >= strlen($fence);
    }

    private function opening(string $line): ?string
    {
        if (1 !== preg_match('/^ {0,3}(`{3,}|~{3,})(.*)$/', $line, $match)) {
            return null;
        }

        // A backtick fence's info string holds no backtick, because such a line is an inline code span.
        return '`' === $match[1][0] && str_contains($match[2], '`') ? null : $match[1];
    }

    /**
     * @param array<int, string> $headings
     */
    private function evidenceIn(array $headings): ?int
    {
        foreach ($headings as $number => $text) {
            if (1 === preg_match('/^evidence(?:\s|$)/i', $text)) {
                return $number;
            }
        }

        return null;
    }

    /**
     * @param array<int, string> $headings
     */
    private function endOf(int $evidence, array $headings, int $lineCount): int
    {
        foreach (array_keys($headings) as $number) {
            if ($number > $evidence) {
                return $number;
            }
        }

        return $lineCount;
    }

    private function joined(string $head, string $added, string $rest): string
    {
        $text = '' === $head ? $added : $head."\n\n".$added;

        return '' === trim($rest) ? $text : $text."\n\n".ltrim($rest, "\n");
    }

    private function resultLines(): string
    {
        return implode("\n", [
            '- Regression tests: '.$this->testsText(),
            '- On the base: '.$this->resultText($this->runs->base),
            '- On the head: '.$this->resultText($this->runs->head),
        ]);
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
}
