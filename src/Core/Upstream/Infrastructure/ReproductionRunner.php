<?php

declare(strict_types=1);

namespace Intakt\Core\Upstream\Infrastructure;

use Closure;
use Intakt\Core\Upstream\Contract\ReproductionOutcome;
use Intakt\Core\Upstream\Contract\ReproductionStatus;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

final readonly class ReproductionRunner
{
    // A reproduction that hangs is itself a finding, so it must not stall the whole run.
    private const float TIMEOUT_SECONDS = 300.0;

    private const string SCRIPT = 'reproduce.php';

    // A script that exits with 0 may never have checked anything, so it must say that the expected behavior held.
    private const string SCRIPT_PASSES = 'REPRODUCTION PASSES';

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private string $projectDir,
        private float $timeoutSeconds = self::TIMEOUT_SECONDS,
    ) {
    }

    public function run(string $directory): ReproductionOutcome
    {
        $script = $directory.'/'.self::SCRIPT;

        if (is_file($script)) {
            return $this->outcome($directory, $this->script($script), $this->scriptPassed(...));
        }

        return $this->outcome($directory, $this->phpunit($directory), static fn (Process $process): bool => $process->isSuccessful());
    }

    private function script(string $script): Process
    {
        return new Process([
            PHP_BINARY,
            '-d',
            'auto_prepend_file='.$this->projectDir.'/vendor/autoload.php',
            $script,
        ], dirname($script), timeout: $this->timeoutSeconds);
    }

    private function scriptPassed(Process $process): bool
    {
        $lines = explode("\n", trim($process->getOutput()));

        return $process->isSuccessful() && self::SCRIPT_PASSES === trim(array_last($lines));
    }

    /**
     * @param Closure(Process): bool $passed
     */
    private function outcome(string $directory, Process $process, Closure $passed): ReproductionOutcome
    {
        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            return new ReproductionOutcome(basename($directory), ReproductionStatus::StillFails, sprintf('Timed out after %g seconds.', $this->timeoutSeconds));
        }

        return new ReproductionOutcome(
            basename($directory),
            $passed($process) ? ReproductionStatus::Passes : ReproductionStatus::StillFails,
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
