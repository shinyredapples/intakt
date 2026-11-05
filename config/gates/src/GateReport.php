<?php

declare(strict_types=1);

namespace Intakt\Gates;

final readonly class GateReport
{
    private const array FIELDS = ['gate', 'ok', 'findings', 'omitted', 'note', 'slowest'];

    private function __construct(
        public string $gate,
        public bool $passing,
        public string $line,
    ) {
    }

    // Because a child that crashes, times out or prints anything but its own gate's line must fail that gate, never pass it.
    public static function fromChild(string $gate, ProcessResult $child): self
    {
        $line = trim($child->output);
        $passing = self::verdictOf($gate, $line);

        if (null !== $passing && $child->exitCode === ($passing ? 0 : 1)) {
            return new self($gate, $passing, $line);
        }

        $reason = 'gate process ended with exit code '.$child->exitCode.' and no valid result line: '.trim($child->errors."\n".$child->output);

        return new self($gate, false, GateResult::failedBecause($gate, $reason)->toJson());
    }

    private static function verdictOf(string $gate, string $line): ?bool
    {
        $report = json_decode($line, true);

        if (!is_array($report) || [] !== array_diff(array_keys($report), self::FIELDS) || $gate !== ($report['gate'] ?? null)) {
            return null;
        }

        return self::consistentVerdict($report);
    }

    /**
     * @param array<array-key, mixed> $report
     */
    private static function consistentVerdict(array $report): ?bool
    {
        $ok = $report['ok'] ?? null;
        $findings = $report['findings'] ?? null;
        $omitted = $report['omitted'] ?? null;

        if (!is_bool($ok) || !is_int($omitted) || !is_array($findings) || !array_is_list($findings) || !array_all($findings, static fn (mixed $finding): bool => is_string($finding))) {
            return null;
        }

        return $ok === ([] === $findings && 0 === $omitted) ? $ok : null;
    }
}
