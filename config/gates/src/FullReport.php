<?php

declare(strict_types=1);

namespace Intakt\Gates;

final readonly class FullReport
{
    public const string VARIABLE = 'INTAKT_GATE_FULL_RESULT';

    public const string SCRATCH = 'intakt-gate-results';

    // Because Intakt's own .gitignore leaves out var/, and the report holds only ASCII, so a tree that does scan var/ (the dashes gate) still passes.
    public const string FOLDER = 'var/check';

    private const int REPORT_FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT;

    public function __construct(
        private string $root,
        private ?ScratchDirectory $results,
    ) {
    }

    /**
     * @return array<string, string|false>
     */
    public static function environmentFor(?ScratchDirectory $results, string $gate): array
    {
        return [self::VARIABLE => $results instanceof ScratchDirectory ? self::keptPath($results, $gate) : false];
    }

    // Because the parent cannot read more than the line, the child leaves its whole result where the parent told it to.
    public static function keep(GateResult $result): void
    {
        $path = getenv(self::VARIABLE);

        if (is_string($path) && '' !== $path) {
            @file_put_contents($path, $result->toFullJson());
        }
    }

    /**
     * @param list<GateReport> $reports
     *
     * @return array{report?: string}
     */
    public function writtenFor(array $reports): array
    {
        if (!$this->results instanceof ScratchDirectory) {
            return [];
        }

        $gates = array_map($this->entryOf(...), $reports);
        $this->results->remove();

        return in_array(null, $gates, true) ? [] : $this->written(['gates' => $gates]);
    }

    // Because a finding cut to the line's width, or one past the line's ten, is no longer whole, such a line cannot stand in for a result the child did not keep.
    /**
     * @param array<array-key, mixed> $line
     */
    public static function isWhole(array $line): bool
    {
        $findings = $line['findings'] ?? null;

        return 0 === ($line['omitted'] ?? null) && is_array($findings) && array_all($findings, static fn (mixed $finding): bool => is_string($finding) && GateResult::widthOf($finding) < GateResult::MAXIMUM_FINDING_LENGTH - 3);
    }

    private static function keptPath(ScratchDirectory $results, string $gate): string
    {
        return $results->path.'/'.$gate.'.json';
    }

    /**
     * @return array<array-key, mixed>|null
     */
    private function entryOf(GateReport $report): ?array
    {
        $line = json_decode($report->line, true);

        if (!is_array($line)) {
            return null;
        }

        $kept = $this->keptFindings($report->gate);
        $entry = array_diff_key($line, ['omitted' => true]);

        if (null !== $kept && $this->accountsFor($report->gate, $kept, $line)) {
            return array_replace($entry, ['findings' => $kept]);
        }

        return self::isWhole($line) ? $entry : null;
    }

    /**
     * @return list<string>|null
     */
    private function keptFindings(string $gate): ?array
    {
        $text = $this->results instanceof ScratchDirectory ? @file_get_contents(self::keptPath($this->results, $gate)) : false;
        $kept = false === $text ? null : json_decode($text, true);
        $findings = is_array($kept) && $gate === ($kept['gate'] ?? null) ? ($kept['findings'] ?? null) : null;

        if (!is_array($findings) || !array_is_list($findings) || !array_all($findings, static fn (mixed $finding): bool => is_string($finding))) {
            return null;
        }

        return $findings;
    }

    // Because only findings that give the very line the gate printed may replace it, the kept ones are cut again and compared.
    /**
     * @param list<string>            $kept
     * @param array<array-key, mixed> $line
     */
    private function accountsFor(string $gate, array $kept, array $line): bool
    {
        $again = json_decode(GateResult::withFindings($gate, $kept)->toJson(), true);

        return is_array($again) && $again['findings'] === ($line['findings'] ?? null) && $again['omitted'] === ($line['omitted'] ?? null);
    }

    /**
     * @param array{gates: list<array<array-key, mixed>>} $report
     *
     * @return array{report?: string}
     */
    private function written(array $report): array
    {
        $folder = $this->root.'/'.self::FOLDER;

        if (!@mkdir($folder, 0o777, true) && !is_dir($folder)) {
            return [];
        }

        $name = self::FOLDER.'/report-'.bin2hex(random_bytes(8)).'.json';
        // Because a fresh name created exclusively never overwrites a file that is already there.
        $file = @fopen($this->root.'/'.$name, 'x');

        if (false === $file) {
            return [];
        }

        fwrite($file, json_encode($report, self::REPORT_FLAGS)."\n");
        fclose($file);

        return ['report' => $name];
    }
}
