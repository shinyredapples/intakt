<?php

declare(strict_types=1);

namespace Intakt\Core\HandBack\Contract;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

// What a build returned and what it was asked: the repository is a bare one whose HEAD names main, as the project's mirror.
#[Exclude]
final readonly class HandBack
{
    public function __construct(
        public string $repository,
        public string $branch,
        public string $bundle,
        public bool $mergeOfMainAsked,
    ) {
    }
}
