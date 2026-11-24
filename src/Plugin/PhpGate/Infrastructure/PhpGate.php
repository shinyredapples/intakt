<?php

declare(strict_types=1);

namespace Intakt\Plugin\PhpGate\Infrastructure;

use Intakt\Core\Job\Contract\EgressHosts;
use Intakt\Plugin\Gate\Contract\CheckSteps;
use Intakt\Plugin\Gate\Contract\DependencyStep;
use Intakt\Plugin\Gate\Contract\Gate;
use Intakt\Plugin\Gate\Contract\LockFiles;
use Intakt\Plugin\PhpGate\Contract\PhpGateSteps;

final readonly class PhpGate implements Gate
{
    // GitHub's archives too, because Composer downloads release files from them after reading Packagist's metadata.
    private const array REGISTRIES = ['repo.packagist.org', 'api.github.com', 'codeload.github.com'];

    // The manifest too, because its platform config and autoload rules change what an install from the same lock file holds.
    private const array LOCK_FILES = ['composer.json', 'composer.lock'];

    public function checkSteps(?string $configuredCommand): CheckSteps
    {
        if (null === $configuredCommand || '' === trim($configuredCommand)) {
            return new CheckSteps(PhpGateSteps::INSTALL, PhpGateSteps::CHECK);
        }

        return new CheckSteps($configuredCommand);
    }

    public function registryHosts(): EgressHosts
    {
        return new EgressHosts(...self::REGISTRIES);
    }

    public function dependencyStep(?string $configuredCommand): ?DependencyStep
    {
        if (null !== $configuredCommand && '' !== trim($configuredCommand)) {
            return null;
        }

        return new DependencyStep(new LockFiles(...self::LOCK_FILES), PhpGateSteps::PHP_VERSION_PROBE, PhpGateSteps::LOCKED_INSTALL, PhpGateSteps::GENERATE, PhpGateSteps::INSTALL);
    }
}
