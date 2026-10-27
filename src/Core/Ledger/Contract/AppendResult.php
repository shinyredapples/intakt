<?php

declare(strict_types=1);

namespace Intakt\Core\Ledger\Contract;

use Symfony\Component\Uid\Uuid;

final readonly class AppendResult
{
    private function __construct(
        public ?Uuid $id,
        public ?string $failure,
    ) {
    }

    public static function appended(Uuid $id): self
    {
        return new self($id, null);
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
