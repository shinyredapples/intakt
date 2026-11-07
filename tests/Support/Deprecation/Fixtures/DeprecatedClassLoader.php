<?php

declare(strict_types=1);

namespace Intakt\Tests\Support\Deprecation\Fixtures;

final readonly class DeprecatedClassLoader
{
    public function load(): bool
    {
        // Because the static analysis of this repository reports a deprecated class named in code, and this helper must load one.
        $name = implode('\\', ['Symfony', 'Component', 'HttpKernel', 'DependencyInjection', 'ServicesResetterInterface']);

        return interface_exists($name);
    }
}
