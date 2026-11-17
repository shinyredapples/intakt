<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Ai\Infrastructure;

use Stringable;
use Symfony\Component\DependencyInjection\EnvVarLoaderInterface;

// A fixed list, because the vault's own loader needs an encrypted vault directory, and it may hold lazy strings since the vault reveals each secret only when it is read.
final readonly class FixedEnvVars implements EnvVarLoaderInterface
{
    /**
     * @param array<string, string|Stringable> $variables
     */
    public function __construct(private array $variables)
    {
    }

    /**
     * @return array<string, string|Stringable>
     */
    public function loadEnvVars(): array
    {
        return $this->variables;
    }
}
