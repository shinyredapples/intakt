<?php

declare(strict_types=1);

namespace Intakt\Core\Memory\Contract;

// The hash is computed by the caller, so Memory never reads git; the symbol is empty when a finding points at a whole file.
final readonly class CodeSnapshot
{
    public function __construct(
        public string $file,
        public string $symbol,
        public string $hash,
    ) {
    }

    public function sameSpotAs(self $other): bool
    {
        return $this->file === $other->file && $this->symbol === $other->symbol;
    }
}
