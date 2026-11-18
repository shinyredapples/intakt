<?php

declare(strict_types=1);

namespace Intakt\Plugin\PhpGate\Contract;

// The PHP gate's own steps, kept in its contract so the docs and a project's configured command can be compared with one home.
final readonly class PhpGateSteps
{
    // The dependencies first, because the check job's clone has no vendor folder and an empty Composer cache.
    public const string INSTALL = 'composer install --no-interaction --no-progress';

    public const string CHECK = 'bin/check --fast';

    // Scripts and plugins off, because a reused install must hold only the locked packages and nothing a package ran.
    public const string LOCKED_INSTALL = 'composer install --no-interaction --no-progress --no-scripts --no-plugins --no-autoloader';

    // Plugins stay on, because symfony/runtime's plugin writes vendor/autoload_runtime.php while the autoloader is dumped.
    public const string GENERATE = 'composer dump-autoload --no-interaction';

    // Read inside the job image, so the version is the one the check runs on and not the orchestrator's.
    public const string PHP_VERSION_PROBE = "php -r 'echo PHP_VERSION;'";
}
