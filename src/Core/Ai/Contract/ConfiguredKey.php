<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Contract;

final readonly class ConfiguredKey
{
    public function __construct(
        public KeyKind $kind,
        public string $lastCharacters,
    ) {
    }
}
