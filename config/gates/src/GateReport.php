<?php

declare(strict_types=1);

namespace Intakt\Gates;

use JsonException;

final readonly class GateReport
{
    private const array FIELDS = ['gate', 'ok', 'findings', 'omitted', 'note', 'slowest'];

    private function __construct(
        public string $gate,
        public string $line,
        private GateLine $origin,
        public ?string $kept = null,
    ) {
    }

    // Because a child that crashes, times out or prints anything but its own gate's line must fail that gate, never pass it.
    public static function fromChild(string $gate, ProcessResult $child): self
    {
        $line = str_ends_with($child->output, "\n") ? substr($child->output, 0, -1) : null;
        $passing = null === $line ? null : self::verdictOf($gate, $line);

        if (null !== $passing && $child->exitCode === ($passing ? 0 : 1)) {
            return new self($gate, $line, GateLine::Printed);
        }

        $reason = 'gate process ended with exit code '.$child->exitCode.' and no valid result line: ';

        return new self($gate, GateResult::failedBecause($gate, $reason.self::endOf($child, $reason))->toJson(), GateLine::Built);
    }

    public function isPassing(): bool
    {
        return true === self::verdictOf($this->gate, $this->line);
    }

    // Because only a kept result that is exactly the canonical encoding of what it decodes to (a repeated key would decode to one value and read as another elsewhere) and that, cut as the line cuts it, gives the line's own findings and omitted count, omits nothing itself and states the line's verdict can stand in for a line the child printed, so the report never shows a gate with two verdicts.
    public function keeping(string $kept): self
    {
        if (GateLine::Built === $this->origin) {
            return $this;
        }

        $whole = json_decode($kept, true);
        $agrees = is_array($whole) && self::isEncodedAs($whole, $kept) && $this->gate === ($whole['gate'] ?? null) && $this->isPassing() === ($whole['ok'] ?? null) && 0 === ($whole['omitted'] ?? null);
        $findings = $agrees ? ($whole['findings'] ?? null) : null;

        $strings = is_array($findings) && array_is_list($findings) ? array_values(array_filter($findings, is_string(...))) : null;

        if (null === $strings || count($strings) !== count($findings)) {
            return $this;
        }

        $recut = $this->shownOf(GateResult::withFindings($this->gate, $strings)->toJson());
        $shown = $this->shownOf($this->line);

        return [$recut['findings'], $recut['omitted']] === [$shown['findings'], $shown['omitted']] ? new self($this->gate, $this->line, $this->origin, $kept) : $this;
    }

    public function lineIsCut(): bool
    {
        $shown = $this->shownOf($this->line);

        return 0 !== $shown['omitted'] || array_any($shown['findings'], static fn (mixed $finding): bool => is_string($finding) && GateResult::isCut($finding));
    }

    /**
     * @param array<array-key, mixed> $report
     */
    private static function isEncodedAs(array $report, string $line): bool
    {
        try {
            return $line === json_encode($report, GateResult::JSON_FLAGS);
        } catch (JsonException) {
            return false;
        }
    }

    /**
     * @return array{findings: array<mixed>, omitted: mixed}
     */
    private function shownOf(string $line): array
    {
        $shown = json_decode($line, true);
        $findings = is_array($shown) ? ($shown['findings'] ?? null) : null;

        return ['findings' => is_array($findings) ? $findings : [], 'omitted' => is_array($shown) ? ($shown['omitted'] ?? null) : null];
    }

    // Because only the exact bytes a gate's own GateResult writes may pass: no stray byte, repeated key or object where a list belongs.
    private static function verdictOf(string $gate, string $line): ?bool
    {
        $report = json_decode($line, true);

        if (!is_array($report) || !self::isEncodedAs($report, $line) || [] !== array_diff(array_keys($report), self::FIELDS) || $gate !== ($report['gate'] ?? null)) {
            return null;
        }

        return self::consistentVerdict($report);
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
