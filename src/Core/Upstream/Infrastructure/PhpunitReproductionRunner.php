<?php

declare(strict_types=1);

namespace Intakt\Core\Upstream\Infrastructure;

use Intakt\Core\Upstream\Contract\ReproductionOutcome;
use Intakt\Core\Upstream\Contract\ReproductionStatus;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Process;

final readonly class PhpunitReproductionRunner
{
    // A reproduction that hangs is itself a finding, so it must not stall the whole run.
    private const int TIMEOUT_SECONDS = 300;

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private string $projectDir,
    ) {
    }

    public function run(string $directory): ReproductionOutcome
    {
        $process = new Process([
            PHP_BINARY,
            $this->projectDir.'/vendor/bin/phpunit',
            '--no-configuration',
            '--bootstrap',
            $this->projectDir.'/vendor/autoload.php',
            '--do-not-record-test-run-history',
            $directory,
        ], $this->projectDir, timeout: self::TIMEOUT_SECONDS);
        $process->run();

        return new ReproductionOutcome(
            basename($directory),
            $process->isSuccessful() ? ReproductionStatus::Passes : ReproductionStatus::StillFails,
            trim($process->getOutput().$process->getErrorOutput()),
        );
    }
}
