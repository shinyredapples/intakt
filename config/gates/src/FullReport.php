<?php

declare(strict_types=1);

namespace Intakt\Gates;

use JsonException;
use RuntimeException;
use SplFileObject;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;

final readonly class FullReport
{
    public const string VARIABLE = 'INTAKT_GATE_FULL_RESULT';

    // Under var/, which every checked tree's .gitignore already leaves out, so a report never shows up as a change and the gates need no rule of their own to skip it.
    public const string FOLDER = 'var/check';

    private const string PREFIX = 'intakt-check-report';

    // Without JSON_UNESCAPED_UNICODE, so a report holds only ASCII and a dash quoted in a finding never fails the dashes gate of a tree that does scan var/.
    private const int REPORT_FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT;

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
        $name = null === $content ? null : $this->writtenAs($root, $content);
        $this->discard();

        return $name;
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

    // Because a named report holds every finding whole: a kept result must account for every finding of its line, and a line may stand in only when it cut nothing.
    /**
     * @return array<array-key, mixed>|null
     */
    private function wholeResultOf(GateReport $report): ?array
    {
        $line = (array) json_decode($report->line, true);
        $file = $this->resultFileOf($report->gate);
        $kept = is_file($file) && is_readable($file) ? json_decode((string) file_get_contents($file), true) : null;

        if (is_array($kept) && $this->agreesWith($kept, $report, $line)) {
            return $kept;
        }

        return $this->isWhole($line) ? $line : null;
    }

    // Because only a kept result of the same gate, verdict and number of findings as the line the verdict came from may stand in for that line.
    /**
     * @param array<array-key, mixed> $kept
     * @param array<array-key, mixed> $line
     */
    private function agreesWith(array $kept, GateReport $report, array $line): bool
    {
        $findings = $kept['findings'] ?? null;
        $shown = $line['findings'] ?? null;
        $omitted = $line['omitted'] ?? null;

        return $report->gate === ($kept['gate'] ?? null)
            && $report->isPassing() === ($kept['ok'] ?? null)
            && is_array($findings)
            && array_is_list($findings)
            && array_all($findings, static fn (mixed $finding): bool => is_string($finding))
            && $report->isPassing() === ([] === $findings)
            && is_array($shown)
            && is_int($omitted)
            && count($findings) === count($shown) + $omitted;
    }

    // Because a finding cut to the line's length cannot be told from one that is exactly that long, both count as cut.
    /**
     * @param array<array-key, mixed> $line
     */
    private function isWhole(array $line): bool
    {
        $findings = $line['findings'] ?? null;

        return 0 === ($line['omitted'] ?? null)
            && is_array($findings)
            && array_all($findings, static fn (mixed $finding): bool => is_string($finding) && preg_match_all('/./su', $finding) < GateResult::MAXIMUM_FINDING_LENGTH);
    }

    /**
     * @param array<string, mixed> $report
     */
    private function encoded(array $report): ?string
    {
        try {
            return json_encode($report, self::REPORT_FLAGS)."\n";
        } catch (JsonException) {
            return null;
        }
    }

    // A fresh random name, created only when no file or link of that name exists ('x'), so a report never overwrites a file or writes through a link.
    private function writtenAs(string $root, string $content): ?string
    {
        $name = self::FOLDER.'/report-'.bin2hex(random_bytes(8)).'.json';

        try {
            new Filesystem()->mkdir($root.'/'.self::FOLDER);
            $file = new SplFileObject($root.'/'.$name, 'x');
        } catch (IOException|RuntimeException) {
            return null;
        }

        return strlen($content) === $file->fwrite($content) ? $name : null;
    }
}
