<?php

declare(strict_types=1);

namespace Intakt\Gates;

use JsonException;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;

// Because a gate line keeps only its first findings, shortened, each child keeps its whole result in a file and the parent gathers them into one report the summary names, so nobody re-runs a check to see more of it.
final readonly class FullReport
{
    public const string DIRECTORY = 'var/check';

    public const string VARIABLE = 'INTAKT_GATE_FULL_RESULT';

    // Because each run names a report of its own, an older summary still points to its own run; only the newest of them are kept.
    public const int KEPT_REPORTS = 20;

    private const array FIELDS = ['gate', 'ok', 'findings', 'note', 'slowest'];

    // Because a finding may quote any byte: an invalid one is replaced, and a non-ASCII one escaped, so the report never holds a long dash a later check of a tree without a .gitignore would read.
    private const int FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;

    private function __construct(private ?ScratchDirectory $results, public string $path)
    {
    }

    public static function start(): self
    {
        return new self(ScratchDirectory::create('intakt-check-results'), self::DIRECTORY.'/report-'.bin2hex(random_bytes(8)).'.json');
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
        } catch (IOException|JsonException) {
            return;
        }
    }

    /**
     * @param array{result: string, failed: list<string>} $summary
     * @param list<GateReport>                            $reports
     */
    public function writeTo(string $root, array $summary, array $reports): ?string
    {
        $gates = array_map($this->resultOf(...), $reports);
        $this->discard();
        $directory = self::directoryIn($root);
        $file = $root.'/'.$this->path;

        // Because Filesystem::dumpFile() writes through a link, a link planted in the checked tree could have it overwrite a file outside it.
        if (null === $directory || file_exists($file) || is_link($file)) {
            return null;
        }

        try {
            new Filesystem()->dumpFile($file, json_encode($summary + ['gates' => $gates], self::FLAGS | JSON_PRETTY_PRINT)."\n");
        } catch (IOException|JsonException) {
            return null;
        }

        self::pruneOlderReportsIn($directory);

        return $this->path;
    }

    // Because a stopped run writes no report, its scratch results must not outlive it.
    public function discard(): void
    {
        $this->results?->remove();
    }

    private static function directoryIn(string $root): ?string
    {
        $directory = $root;

        foreach (explode('/', self::DIRECTORY) as $part) {
            $directory .= '/'.$part;

            if (is_link($directory)) {
                return null;
            }

            try {
                new Filesystem()->mkdir($directory);
            } catch (IOException) {
                return null;
            }
        }

        return $directory;
    }

    private static function pruneOlderReportsIn(string $directory): void
    {
        $reports = array_filter(glob($directory.'/report-*.json') ?: [], static fn (string $file): bool => is_file($file) && !is_link($file));
        $ages = array_map(static fn (string $file): int => (int) filemtime($file), $reports);
        arsort($ages);

        try {
            new Filesystem()->remove(array_map(static fn (int $key): string => $reports[$key], array_slice(array_keys($ages), self::KEPT_REPORTS)));
        } catch (IOException) {
            return;
        }
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
            && ([] === $findings) === ($kept['ok'] ?? null)
            && (!array_key_exists('note', $kept) || is_string($kept['note']))
            && (!array_key_exists('slowest', $kept) || self::isSlowestList($kept['slowest']));
    }

    private static function isSlowestList(mixed $slowest): bool
    {
        return is_array($slowest) && array_is_list($slowest) && array_all($slowest, static fn (mixed $entry): bool => is_array($entry)
            && ['file', 'seconds'] === array_keys($entry)
            && is_string($entry['file'])
            && (is_int($entry['seconds']) || (is_float($entry['seconds']) && is_finite($entry['seconds']))));
    }

    private function fileOf(string $gate): string
    {
        return $this->results?->path.'/'.$gate.'.json';
    }
}
