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
        [$evidence, $end, $open] = $this->evidenceSectionIn($lines);

        if (null === $evidence) {
            return $this->joined(rtrim($body."\n".($open ?? '')), "## Evidence\n\n".$this->resultLines(), '');
        }

        $head = rtrim(implode("\n", array_slice($lines, 0, $end))."\n".($end >= count($lines) ? ($open ?? '') : ''));
        $rest = implode("\n", array_slice($lines, $end));

        return $this->joined($head, $this->resultLines(), $rest);
    }

    private function joined(string $head, string $added, string $rest): string
    {
        $text = '' === $head ? $added : $head."\n\n".$added;

        return '' === trim($rest) ? $text : $text."\n\n".ltrim($rest, "\n");
    }

    /**
     * @param list<string> $lines
     *
     * @return array{0: ?int, 1: int, 2: ?string} the Evidence heading's line, the line its section ends before and the marker that closes a fence left open at the end
     */
    private function evidenceSectionIn(array $lines): array
    {
        $fence = null;
        $evidence = null;

        foreach ($lines as $number => $line) {
            if (null !== $fence) {
                $fence = $this->closes($line, $fence) ? null : $fence;

                continue;
            }

            $fence = $this->opening($line);

            if (null !== $fence || 1 !== preg_match('/^ {0,3}#{1,6}(?:[ \t]+(.*?))?(?:[ \t]+#+)?[ \t]*$/', $line, $heading)) {
                continue;
            }

            if (null !== $evidence) {
                return [$evidence, $number, null];
            }

            $evidence = 1 === preg_match('/^evidence(?:\s|$)/i', $heading[1] ?? '') ? $number : null;
        }

        return [$evidence, count($lines), null === $fence ? null : $fence];
    }

    private function opening(string $line): ?string
    {
        if (1 !== preg_match('/^ {0,3}(`{3,}|~{3,})(.*)$/', $line, $match)) {
            return null;
        }

        // A backtick fence's info string holds no backtick, because that line is an inline code span.
        return '`' === $match[1][0] && str_contains($match[2], '`') ? null : $match[1];
    }

    // Only the same character, repeated at least as often as the opening line, and nothing else on the line.
    private function closes(string $line, string $fence): bool
    {
        return 1 === preg_match('/^ {0,3}('.preg_quote($fence[0], '/').'+)[ \t]*$/', $line, $match) && strlen($match[1]) >= strlen($fence);
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
        return $run?->value ?? 'not run';
    }
}
