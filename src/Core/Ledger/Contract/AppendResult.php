<?php

declare(strict_types=1);

namespace Intakt\Core\Ledger\Contract;

final readonly class AppendResult
{
    private function __construct(
        public ?int $sequence,
        public ?string $failure,
    ) {
    }

    public static function appended(int $sequence): self
    {
        return new self($sequence, null);
    }

    public static function failed(string $reason): self
    {
        return new self(null, $reason);
    }

    public function succeeded(): bool
    {
        return null === $this->failure;
    }
}
