<?php

declare(strict_types=1);

namespace Intakt\Gates;

final readonly class GateReport
{
    private const array FIELDS = ['gate', 'ok', 'findings', 'omitted', 'note', 'slowest'];

    private function __construct(
        public string $gate,
        public string $line,
    ) {
    }

    // Because a child that crashes, times out or prints anything but its own gate's line must fail that gate, never pass it.
    public static function fromChild(string $gate, ProcessResult $child): self
    {
        $line = trim($child->output);
        $passing = self::verdictOf($gate, $line);

        if (null !== $passing && $child->exitCode === ($passing ? 0 : 1)) {
            return new self($gate, $line);
        }

        $reason = 'gate process ended with exit code '.$child->exitCode.' and no valid result line: ';

        return new self($gate, GateResult::failedBecause($gate, $reason.self::endOf($child, $reason))->toJson());
    }

    // Because a finding is cut at its end, and the end of what a child wrote names why it stopped (a timeout, a signal, a fatal error).
    private static function endOf(ProcessResult $child, string $reason): string
    {
        $written = trim((string) preg_replace('/\s+/', ' ', $child->output."\n".$child->errors));
        $room = GateResult::MAXIMUM_FINDING_LENGTH - mb_strwidth($reason);

        if (mb_strwidth($written) <= $room) {
            return $written;
        }

        $end = mb_substr($written, -$room);

        while (mb_strwidth('...'.$end) > $room) {
            $end = mb_substr($end, 1);
        }

        return '...'.$end;
    }

    public function isPassing(): bool
    {
        return true === self::verdictOf($this->gate, $this->line);
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
