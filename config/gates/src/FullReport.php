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

    private const string NAME = '/^report-[0-9a-f]{16}\.json$/';

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

    // Because in a tree without Git no .gitignore leaves the reports out, and a report would otherwise be input of the next check.
    public static function isReport(string $path): bool
    {
        return 1 === preg_match(self::NAME, $path);
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

        $name = 'report-'.bin2hex(random_bytes(8)).'.json';
        $report = $summary + ['gates' => array_map($this->wholeResultOf(...), $reports)];
        $written = $this->written($root.'/'.$name, $report);
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

    /**
     * @return array<array-key, mixed>
     */
    private function wholeResultOf(GateReport $report): array
    {
        $kept = is_file($this->resultFileOf($report->gate)) ? json_decode((string) file_get_contents($this->resultFileOf($report->gate)), true) : null;

        if (is_array($kept) && $this->agreesWith($kept, $report)) {
            return $kept;
        }

        return (array) json_decode($report->line, true);
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
    private function written(string $file, array $report): bool
    {
        try {
            new Filesystem()->dumpFile($file, json_encode($report, GateResult::JSON_FLAGS | JSON_PRETTY_PRINT)."\n");
        } catch (IOException|JsonException) {
            return false;
        }

        return true;
    }
}
