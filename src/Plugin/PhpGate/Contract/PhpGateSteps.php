<?php

declare(strict_types=1);

namespace Intakt\Plugin\PhpGate\Contract;

// The PHP gate's own steps, kept in its contract so the docs and a project's configured command can be compared with one home.
final readonly class PhpGateSteps
{
    // The dependencies first, because the check job's clone has no vendor folder and an empty Composer cache.
    public const string INSTALL = 'composer install --no-interaction --no-progress';

    public const string CHECK = 'bin/check --fast';

    // The PHP version is not among them, because it comes with the job image, which is part of a snapshot's key.
    public const array LOCK_FILES = ['composer.json', 'composer.lock'];

    // No scripts and no plugins, so no package's own code runs, and no autoloader, so the check makes it and the runtime files from its own tree.
    public const string CLEAN_INSTALL = 'composer install --no-interaction --no-progress --no-scripts --no-plugins --no-autoloader';

    public const string VENDOR = 'vendor';
}
