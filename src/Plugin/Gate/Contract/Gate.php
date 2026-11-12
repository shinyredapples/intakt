<?php

declare(strict_types=1);

namespace Intakt\Plugin\Gate\Contract;

interface Gate
{
    // Shell steps run in order in the check job's clone, so a project's own command replaces only what the gate would run itself.
    /**
     * @return non-empty-list<string>
     */
    public function checkSteps(?string $configuredCommand): array;

    // The hosts the steps fetch dependencies from, because the check job starts with an empty dependency cache.
    /**
     * @return list<string>
     */
    public function registryHosts(): array;
}
