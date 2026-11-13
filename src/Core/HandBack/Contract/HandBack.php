<?php

declare(strict_types=1);

namespace Intakt\Core\HandBack\Contract;

use Intakt\Plugin\Forge\Contract\JobCredentials;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final readonly class HandBack
{
    public function __construct(
        public string $repository,
        public string $branch,
        public string $bundle,
        public bool $mergeOfMainAsked,
        public JobCredentials $mountedCredentials,
    ) {
    }
}
