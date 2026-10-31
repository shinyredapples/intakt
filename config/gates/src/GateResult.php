<?php

declare(strict_types=1);

namespace Intakt\Gates;

final readonly class GateResult
{
    private const int MAXIMUM_FINDINGS = 10;
    private const int MAXIMUM_FINDING_LENGTH = 300;

    /**
     * @param list<string> $findings
     */
    private function __construct(
        public string $gate,
        public array $findings,
        private ?string $note = null,
    ) {
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

        return json_encode(null === $this->note ? $report : $report + ['note' => $this->note], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function shorten(string $finding): string
    {
        $oneLine = trim((string) preg_replace('/\s+/', ' ', $finding));

        return mb_strimwidth($oneLine, 0, self::MAXIMUM_FINDING_LENGTH, '...');
    }
}
