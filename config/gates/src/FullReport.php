<?php

declare(strict_types=1);

namespace Intakt\Gates;

use JsonException;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;

final readonly class FullReport
{
    public const string VARIABLE = 'INTAKT_GATE_FULL_RESULT';

    private const string PREFIX = 'intakt-check-report';

    // \A and \z, because $ also matches before a final newline, which a file name may end in.
    private const string NAME = '/\Areport-[0-9a-f]{16}\.json\z/';

    private function __construct(private ?ScratchDirectory $scratch)
    {
    }

    public static function start(): self
    {
        return new self(ScratchDirectory::create(self::PREFIX));
    }

    // Because a child's stdout holds only its shortened gate line, it leaves its whole result in the file the parent named.
    /**
     * @param array<string, string> $environment
     */
    public static function keep(GateResult $result, array $environment): void
    {
        $file = $environment[self::VARIABLE] ?? '';

        if ('' === $file) {
            return;
        }

        // Because keeping the result must never cost the gate line the child prints next.
        try {
            new Filesystem()->dumpFile($file, $result->toFullJson());
        } catch (IOException|JsonException) {
            return;
        }
    }

    // Because a tree without Git or without the ignore rule would make an earlier report input of the next check, while an ordinary file that only shares the name must stay input: the name carries the hash of what the runner wrote.
    public static function isReport(string $root, string $path): bool
    {
        $file = $root.'/'.$path;

        if (1 !== preg_match(self::NAME, $path) || !is_file($file) || is_link($file)) {
            return false;
        }

        $content = file_get_contents($file);

        return false !== $content && self::nameOf($content) === $path;
    }

    // Because without a scratch folder no child may keep a path it inherited.
    /**
     * @return array<string, string|false>
     */
    public function environmentFor(string $gate): array
    {
        return [self::VARIABLE => $this->scratch instanceof ScratchDirectory ? $this->resultFileOf($gate) : false];
    }

    /**
     * @param array{result: string, failed: list<string>} $summary
     * @param list<GateReport>                            $reports
     */
    public function writeTo(string $root, array $summary, array $reports): ?string
    {
        if (!$this->scratch instanceof ScratchDirectory) {
            return null;
        }

        $gates = array_map($this->wholeResultOf(...), $reports);
        $content = in_array(null, $gates, true) ? null : $this->encoded($summary + ['gates' => $gates]);
        $name = null === $content ? null : self::nameOf($content);
        $written = null !== $name && $this->written($root.'/'.$name, (string) $content);
        $this->discard();

        return $written ? $name : null;
    }

    // Because a scratch folder left in the system temp directory costs nothing, while a failed cleanup must never cost the verdict.
    public function discard(): void
    {
        try {
            $this->scratch?->remove();
        } catch (IOException) {
            return;
        }
    }

    private function resultFileOf(string $gate): string
    {
        return $this->scratch?->path.'/'.$gate.'.json';
    }

    private static function nameOf(string $content): string
    {
        return 'report-'.substr(hash('sha256', $content), 0, 16).'.json';
    }

    // Because a named report holds every finding: a line that dropped findings cannot stand in for a result the child failed to keep.
    /**
     * @return array<array-key, mixed>|null
     */
    private function wholeResultOf(GateReport $report): ?array
    {
        $kept = is_file($this->resultFileOf($report->gate)) ? json_decode((string) file_get_contents($this->resultFileOf($report->gate)), true) : null;

        if (is_array($kept) && $this->agreesWith($kept, $report)) {
            return $kept;
        }

        $line = (array) json_decode($report->line, true);

        return 0 === ($line['omitted'] ?? 0) ? $line : null;
    }

    // Because only a kept result of the same gate and verdict as the line the verdict came from may stand in for that line.
    /**
     * @param array<array-key, mixed> $kept
     */
    private function agreesWith(array $kept, GateReport $report): bool
    {
        $findings = $kept['findings'] ?? null;

        return $report->gate === ($kept['gate'] ?? null)
            && $report->isPassing() === ($kept['ok'] ?? null)
            && is_array($findings)
            && array_is_list($findings)
            && array_all($findings, static fn (mixed $finding): bool => is_string($finding))
            && $report->isPassing() === ([] === $findings);
    }

    /**
     * @param array<string, mixed> $report
     */
    private function encoded(array $report): ?string
    {
        try {
            return json_encode($report, GateResult::JSON_FLAGS | JSON_PRETTY_PRINT)."\n";
        } catch (JsonException) {
            return null;
        }
    }

    private function written(string $file, string $content): bool
    {
        try {
            new Filesystem()->dumpFile($file, $content);
        } catch (IOException) {
            return false;
        }

        return true;
    }
}
