<?php

declare(strict_types=1);

namespace Intakt\Plugin\PhpGate\Contract;

// The PHP gate's own steps, kept in its contract so the docs and a project's configured command can be compared with one home.
final readonly class PhpGateSteps
{
    // The dependencies first, because the check job's clone has no vendor folder and an empty Composer cache.
    public const string INSTALL = 'composer install --no-interaction --no-progress';

    public const string CHECK = 'bin/check --fast';
}
