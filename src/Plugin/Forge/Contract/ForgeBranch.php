<?php

declare(strict_types=1);

namespace Intakt\Plugin\Forge\Contract;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final readonly class ForgeBranch
{
    public function __construct(
        public string $name,
        public CommitId $head,
        public bool $isDefault,
        public bool $isProtected,
        public bool $isRelease,
    ) {
    }

    // One rule for every forge, because the branches cleanup must never pick are the same wherever they live.
    public static function isReleaseName(string $name): bool
    {
        return 1 === preg_match('~^release(?:[/-]|$)~i', $name);
    }
}
