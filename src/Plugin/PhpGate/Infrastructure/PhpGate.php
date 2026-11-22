<?php

declare(strict_types=1);

namespace Intakt\Plugin\PhpGate\Infrastructure;

use Intakt\Plugin\Gate\Contract\DependencyStep;
use Intakt\Plugin\Gate\Contract\Gate;
use Intakt\Plugin\PhpGate\Contract\PhpGateSteps;

final readonly class PhpGate implements Gate
{
    // GitHub's archives too, because Composer downloads release files from them after reading Packagist's metadata.
    private const array REGISTRIES = ['repo.packagist.org', 'api.github.com', 'codeload.github.com'];

    // The manifest too, because its platform config and autoload rules change what an install from the same lock file holds.
    private const array LOCK_FILES = ['composer.json', 'composer.lock'];

    public function checkSteps(?string $configuredCommand): array
    {
        if (null === $configuredCommand || '' === trim($configuredCommand)) {
            return [PhpGateSteps::INSTALL, PhpGateSteps::CHECK];
        }

        return [$configuredCommand];
    }

    public function registryHosts(): array
    {
        return self::REGISTRIES;
    }

    public function dependencyStep(?string $configuredCommand): ?DependencyStep
    {
        if (null !== $configuredCommand && '' !== trim($configuredCommand)) {
            return null;
        }

        return new DependencyStep(self::LOCK_FILES, PhpGateSteps::PHP_VERSION_PROBE, PhpGateSteps::LOCKED_INSTALL, PhpGateSteps::GENERATE, PhpGateSteps::INSTALL);
    }
}
