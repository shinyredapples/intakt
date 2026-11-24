<?php

declare(strict_types=1);

namespace Intakt\Plugin\Gate\Contract;

use Intakt\Core\Git\Contract\Paths;
use Intakt\Core\Job\Contract\EgressHosts;

interface Gate
{
    // Shell steps run in order in the check job's clone, so a project's own command replaces only what the gate would run itself.
    public function checkSteps(?string $configuredCommand): CheckSteps;

    // Test layout is language-specific, so the gate says which repository-relative paths are test files.
    public function isTestFile(string $path): bool;

    // One shell step for each given test file (never a project's own command), so the first test that fails ends the run.
    public function testSteps(Paths $tests): CheckSteps;

    // The hosts the steps fetch dependencies from, because the check job starts with an empty dependency cache.
    public function registryHosts(): EgressHosts;

    // Null when the gate names no lock inputs (a project's own command installs what it needs), so the check installs every time.
    public function dependencyStep(?string $configuredCommand): ?DependencyStep;
}
