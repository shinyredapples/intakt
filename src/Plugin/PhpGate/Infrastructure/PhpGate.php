<?php

declare(strict_types=1);

namespace Intakt\Plugin\PhpGate\Infrastructure;

use Intakt\Plugin\Gate\Contract\Gate;

final readonly class PhpGate implements Gate
{
    // The dependencies first, because the check job's clone has no vendor folder and an empty Composer cache.
    private const string INSTALL = 'composer install --no-interaction --no-progress';

    private const string CHECK = 'bin/check --fast';

    // Packagist's metadata, and GitHub's archives, which Composer downloads release files from.
    private const array REGISTRIES = ['repo.packagist.org', 'api.github.com', 'codeload.github.com'];

    public function checkSteps(?string $configuredCommand): array
    {
        if (null === $configuredCommand || '' === trim($configuredCommand)) {
            return [self::INSTALL, self::CHECK];
        }

        return [$configuredCommand];
    }

    public function registryHosts(): array
    {
        return self::REGISTRIES;
    }
}
