<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Ai\Infrastructure;

use Stringable;
use Symfony\Component\DependencyInjection\EnvVarLoaderInterface;

// Stands in for the secrets vault's loader, which serves its entries the same way, some as lazy strings revealed only when read.
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
