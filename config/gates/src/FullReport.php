<?php

declare(strict_types=1);

namespace Intakt\Gates;

use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;

// Because a gate line keeps only its first findings, shortened, each child keeps its whole result in a file and the parent gathers them into one report the summary names, so nobody re-runs a check to see more of it.
final readonly class FullReport
{
    public const string PATH = 'var/check/report.json';

    public const string VARIABLE = 'INTAKT_GATE_FULL_RESULT';

    private const array FIELDS = ['gate', 'ok', 'findings', 'note', 'slowest'];

    // Because a finding may quote any byte: an invalid one is replaced, and a non-ASCII one escaped, so the report never holds a long dash a later check of a tree without a .gitignore would read.
    private const int FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;

    private function __construct(private ?ScratchDirectory $results)
    {
    }

    public static function start(): self
    {
        return new self(ScratchDirectory::create('intakt-check-results'));
    }

    /**
     * @return array<string, string|false>
     */
    public function environmentFor(string $gate): array
    {
        return [self::VARIABLE => $this->results instanceof ScratchDirectory ? $this->fileOf($gate) : false];
    }

    // Because the gate's own tools and tests may start a gate child too, which must not write over this gate's file.
    public static function fileAskedFor(): ?string
    {
        $file = getenv(self::VARIABLE);
        putenv(self::VARIABLE);

        return is_string($file) && '' !== $file ? $file : null;
    }

    public static function keep(GateResult $result, ?string $file): void
    {
        if (null === $file) {
            return;
        }

        // Because the gate line alone decides the verdict, a result that cannot be kept leaves the parent to report that line instead.
        try {
            new Filesystem()->dumpFile($file, json_encode($result->whole(), self::FLAGS));
        } catch (IOException) {
            return;
        }
    }

    /**
     * @param array{result: string, failed: list<string>} $summary
     * @param list<GateReport>                            $reports
     */
    public function writeTo(string $root, array $summary, array $reports): bool
    {
        $gates = array_map($this->resultOf(...), $reports);
        $this->results?->remove();

        try {
            new Filesystem()->dumpFile($root.'/'.self::PATH, json_encode($summary + ['gates' => $gates], self::FLAGS | JSON_PRETTY_PRINT)."\n");
        } catch (IOException) {
            return false;
        }

        return true;
    }

    // Because the gate line decides the verdict, a kept result counts only when it is that gate's and agrees with the line; otherwise the line itself is reported.
    /**
     * @return array<array-key, mixed>
     */
    private function resultOf(GateReport $report): array
    {
        $kept = $this->keptResultOf($report->gate);

        if (null !== $kept && $kept['ok'] === $report->isPassing()) {
            return $kept;
        }

        $line = json_decode($report->line, true, flags: JSON_THROW_ON_ERROR);

        return is_array($line) ? $line : [];
    }

    /**
     * @return array<array-key, mixed>|null
     */
    private function keptResultOf(string $gate): ?array
    {
        $file = $this->results instanceof ScratchDirectory ? $this->fileOf($gate) : '';
        $text = is_file($file) && is_readable($file) ? file_get_contents($file) : false;
        $kept = false === $text ? null : json_decode($text, true);

        return is_array($kept) && $this->isResultOf($gate, $kept) ? $kept : null;
    }

    /**
     * @param array<array-key, mixed> $kept
     */
    private function isResultOf(string $gate, array $kept): bool
    {
        $findings = $kept['findings'] ?? null;

        return $gate === ($kept['gate'] ?? null)
            && [] === array_diff(array_keys($kept), self::FIELDS)
            && is_array($findings) && array_is_list($findings) && array_all($findings, static fn (mixed $finding): bool => is_string($finding))
            && ([] === $findings) === ($kept['ok'] ?? null);
    }

    private function fileOf(string $gate): string
    {
        return $this->results?->path.'/'.$gate.'.json';
    }
}
