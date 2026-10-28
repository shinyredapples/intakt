<?php

declare(strict_types=1);

namespace Intakt\Core\Upstream\Infrastructure;

use Intakt\Core\Upstream\Contract\ReproductionOutcome;
use Intakt\Core\Upstream\Contract\ReproductionStatus;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

final readonly class PhpunitReproductionRunner
{
    // A reproduction that hangs is itself a finding, so it must not stall the whole run.
    private const float TIMEOUT_SECONDS = 300.0;

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private string $projectDir,
        private float $timeoutSeconds = self::TIMEOUT_SECONDS,
    ) {
    }

    public function run(string $directory): ReproductionOutcome
    {
        $process = $this->phpunit($directory);

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            return new ReproductionOutcome(basename($directory), ReproductionStatus::StillFails, sprintf('Timed out after %d seconds.', $this->timeoutSeconds));
        }

        return new ReproductionOutcome(
            basename($directory),
            $process->isSuccessful() ? ReproductionStatus::Passes : ReproductionStatus::StillFails,
            trim($process->getOutput().$process->getErrorOutput()),
        );
    }

    // Empty, skipped and assertion-free runs fail, because only a test that ran and asserted can show a fix.
    private function phpunit(string $directory): Process
    {
        return new Process([
            PHP_BINARY,
            $this->projectDir.'/vendor/bin/phpunit',
            '--no-configuration',
            '--bootstrap',
            $this->projectDir.'/vendor/autoload.php',
            '--do-not-record-test-run-history',
            '--fail-on-empty-test-suite',
            '--fail-on-skipped',
            '--fail-on-incomplete',
            '--fail-on-risky',
            $directory,
        ], $this->projectDir, timeout: $this->timeoutSeconds);
    }
}
