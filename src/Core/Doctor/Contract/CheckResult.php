<?php

declare(strict_types=1);

namespace Intakt\Core\Doctor\Contract;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final readonly class CheckResult
{
    private function __construct(
        public bool $passed,
        public string $fix,
    ) {
    }

    public static function passed(): self
    {
        return new self(true, '');
    }

    // The fix is plain words for the owner, never a stack trace or a credential.
    public static function failed(string $fix): self
    {
        return new self(false, $fix);
    }
}
