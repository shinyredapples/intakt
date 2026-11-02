<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

final readonly class TokenCount
{
    public function __construct(
        public int $prompt,
        public int $completion,
        public int $total,
    ) {
    }

    public static function none(): self
    {
        return new self(0, 0, 0);
    }

    public function plus(self $other): self
    {
        return new self($this->prompt + $other->prompt, $this->completion + $other->completion, $this->total + $other->total);
    }
}
