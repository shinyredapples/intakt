<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Contract;

final readonly class VerdictDocument
{
    /**
     * @param array<string, mixed> $verdict
     * @param array<string, mixed> $finding
     */
    private function __construct(
        private array $verdict,
        private array $finding,
    ) {
    }

    public static function fromTheDesign(): self
    {
        return new self(
            ['verdict' => 'changes', 'resolved' => ['F1', 'F2'], 'follow_ups' => ['...']],
            [
                'id' => 'F3', 'severity' => 'P1', 'file' => '...', 'line' => 12, 'symbol' => '...',
                'text' => '...', 'fixable_by' => 'builder', 'repeat' => false, 'requirement' => '...',
                'evidence' => ['probe' => '...', 'output' => '...'],
            ],
        );
    }

    public function with(string $key, mixed $value): self
    {
        return clone($this, ['verdict' => [...$this->verdict, $key => $value]]);
    }

    public function without(string $key): self
    {
        return clone($this, ['verdict' => array_diff_key($this->verdict, [$key => true])]);
    }

    public function withFinding(string $key, mixed $value): self
    {
        return clone($this, ['finding' => [...$this->finding, $key => $value]]);
    }

    public function withoutFinding(string $key): self
    {
        return clone($this, ['finding' => array_diff_key($this->finding, [$key => true])]);
    }

    public function json(): string
    {
        return json_encode(['findings' => [$this->finding], ...$this->verdict], JSON_THROW_ON_ERROR);
    }
}
