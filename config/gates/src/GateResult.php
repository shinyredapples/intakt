<?php

declare(strict_types=1);

namespace Intakt\Gates;

final readonly class GateResult
{
    private const int MAXIMUM_FINDINGS = 10;
    private const int MAXIMUM_FINDING_LENGTH = 300;

    /** @var array<string, float> */
    private array $slowest;

    /**
     * @param list<string> $findings
     */
    private function __construct(
        public string $gate,
        public array $findings,
        private ?string $note = null,
    ) {
        $this->slowest = [];
    }

    public static function passed(string $gate): self
    {
        return new self($gate, []);
    }

    public static function passedBecause(string $gate, string $note): self
    {
        return new self($gate, [], $note);
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
        return clone($this, ['note' => $note]);
    }

    /**
     * @param array<string, float> $slowest
     */
    public function withSlowest(array $slowest): self
    {
        return clone($this, ['slowest' => $slowest]);
    }

    public function isPassing(): bool
    {
        return [] === $this->findings;
    }

    public function toJson(): string
    {
        $shown = array_slice($this->findings, 0, self::MAXIMUM_FINDINGS);

        $report = [
            'gate' => $this->gate,
            'ok' => $this->isPassing(),
            'findings' => array_map($this->shorten(...), $shown),
            'omitted' => count($this->findings) - count($shown),
        ];

        $report += null === $this->note ? [] : ['note' => $this->note];
        $report += [] === $this->slowest ? [] : ['slowest' => $this->slowestList()];

        return json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return list<array{file: string, seconds: float}>
     */
    private function slowestList(): array
    {
        $list = [];

        foreach ($this->slowest as $file => $seconds) {
            $list[] = ['file' => $file, 'seconds' => round($seconds, 3)];
        }

        return $list;
    }

    private function shorten(string $finding): string
    {
        $oneLine = trim((string) preg_replace('/\s+/', ' ', $finding));

        return mb_strimwidth($oneLine, 0, self::MAXIMUM_FINDING_LENGTH, '...');
    }
}
