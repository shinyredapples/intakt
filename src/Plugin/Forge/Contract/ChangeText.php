<?php

declare(strict_types=1);

namespace Intakt\Plugin\Forge\Contract;

// The builder's words for a pull request: untrusted, so a forge scans them before it writes them.
final readonly class ChangeText
{
    public function __construct(
        public string $title,
        public string $body,
    ) {
    }
}
