<?php

declare(strict_types=1);

namespace Intakt\Gates;

final readonly class GateResult
{
    private const int MAXIMUM_FINDINGS = 10;
    public const int MAXIMUM_FINDING_LENGTH = 300;
    public const int JSON_FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    /**
     * @param list<string>                                         $findings
     * @param array{note?: string, slowest?: array<string, float>} $extras
     */
    private function __construct(
        public string $gate,
        public array $findings,
        private array $extras = [],
    ) {
    }

    public static function passed(string $gate): self
    {
        return new self($gate, []);
    }

    public static function passedBecause(string $gate, string $note): self
    {
        return new self($gate, [], ['note' => $note]);
    }

    /**
     * @param list<string> $findings
     */
    public static function withFindings(string $gate, array $findings): self
    {
        return new self($gate, $findings);
    }

    public static function failedBecause(string $gate, string $reason): self
    {
        return new self($gate, [$reason]);
    }

    public function noted(string $note): self
    {
        return new self($this->gate, $this->findings, ['note' => $note] + $this->extras);
    }

    /**
     * @param array<string, float> $slowest
     */
    public function withSlowest(array $slowest): self
    {
        return new self($this->gate, $this->findings, ['slowest' => $slowest] + $this->extras);
    }

    public function isPassing(): bool
    {
        return [] === $this->findings;
    }

    public function toJson(): string
    {
        $shown = array_slice($this->findings, 0, self::MAXIMUM_FINDINGS);

        return json_encode($this->reportOf(array_map($this->shorten(...), $shown), count($this->findings) - count($shown)), self::JSON_FLAGS);
    }

    // Because the line keeps at most ten findings of at most 300 columns, the child also keeps every finding whole for the parent to collect.
    public function toFullJson(): string
    {
        return json_encode($this->reportOf(array_map(self::scrubbed(...), $this->findings), 0), self::JSON_FLAGS);
    }

    // Because the line cannot tell a finding it shortened from one that was just as long, a finding that long counts as cut either way.
    public static function isCut(string $finding): bool
    {
        return self::measure($finding) >= self::MAXIMUM_FINDING_LENGTH - 3;
    }

    // Because a broken byte cannot go into JSON, the line and the kept whole result both replace it, so both show the same replacement whatever it is.
    public static function scrubbed(string $finding): string
    {
        if (function_exists('mb_scrub')) {
            return mb_scrub($finding, 'UTF-8');
        }

        $decoded = json_decode(json_encode($finding, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE), true);

        return is_string($decoded) ? $decoded : '';
    }

    private function shorten(string $finding): string
    {
        $oneLine = trim((string) preg_replace('/\s+/', ' ', self::scrubbed($finding)));

        if (self::measure($oneLine) <= self::MAXIMUM_FINDING_LENGTH) {
            return $oneLine;
        }

        // The environment gate reports a missing mbstring through here, so the cut must work without it.
        if (!function_exists('mb_strimwidth')) {
            return implode('', array_slice(self::characters($oneLine), 0, self::MAXIMUM_FINDING_LENGTH - 3)).'...';
        }

        return mb_strimwidth($oneLine, 0, self::MAXIMUM_FINDING_LENGTH, '...', 'UTF-8');
    }

    /**
     * @param list<string> $findings
     *
     * @return array<string, mixed>
     */
    private function reportOf(array $findings, int $omitted): array
    {
        $report = [
            'gate' => $this->gate,
            'ok' => $this->isPassing(),
            'findings' => $findings,
            'omitted' => $omitted,
        ];

        $report += isset($this->extras['note']) ? ['note' => $this->extras['note']] : [];

        return $report + ([] === ($this->extras['slowest'] ?? []) ? [] : ['slowest' => $this->slowestList()]);
    }

    /**
     * @return list<array{file: string, seconds: float}>
     */
    private function slowestList(): array
    {
        $list = [];

        foreach ($this->extras['slowest'] ?? [] as $file => $seconds) {
            $list[] = ['file' => $file, 'seconds' => round($seconds, 3)];
        }

        return $list;
    }

    // Because shorten() cuts by display width, the cut check measures the same way, and by characters where mbstring is missing.
    private static function measure(string $text): int
    {
        return function_exists('mb_strwidth') ? mb_strwidth($text, 'UTF-8') : count(self::characters($text));
    }

    /**
     * @return list<string>
     */
    private static function characters(string $text): array
    {
        $characters = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);

        return false === $characters ? str_split($text) : $characters;
    }
}
