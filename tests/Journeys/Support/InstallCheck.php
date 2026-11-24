<?php

declare(strict_types=1);

namespace Intakt\Tests\Journeys\Support;

use Closure;
use Symfony\Component\Filesystem\Filesystem;

// The clone is always stopped and removed in the end, so a failing step never leaves containers or a folder behind.
final readonly class InstallCheck
{
    public const string ADDRESS = 'http://localhost:7781';
    public const array KEYS = ['ANTHROPIC_API_KEY', 'OPENAI_API_KEY'];
    private const int TAIL_LINES = 40;
    private const string REDACTED = '[redacted]';

    // The runner takes a shell command and a folder and answers the exit code and the output, so a test needs no docker; only the key names of KEYS are read from the environment because nothing else may reach the steps.
    /**
     * @param Closure(string, string): array{0: int, 1: string} $runner
     * @param array<string, string>                             $environment
     */
    public function __construct(
        private Closure $runner,
        private string $temporary,
        private string $logs,
        private string $checkout,
        private array $environment = [],
        private Filesystem $files = new Filesystem(),
    ) {
    }

    /**
     * @throws InstallCheckCouldNotRun
     */
    public function run(string $repository): InstallReport
    {
        if ('' === $repository || str_starts_with($repository, '-')) {
            throw new InstallCheckCouldNotRun('the repository address must not be empty or start with a dash');
        }

        $this->files->mkdir($this->logs);
        $clone = null;

        try {
            [$code] = ($this->runner)('docker compose version', $this->temporary);

            if (0 !== $code) {
                throw new InstallCheckCouldNotRun('docker compose is not available, so the install cannot be tried');
            }

            $clone = $this->cloneInto($repository);
            $commands = array_values(array_filter(
                ReadmeInstallBlock::commandsIn((string) @file_get_contents($clone.'/README.md')),
                static fn (string $command): bool => !str_starts_with($command, 'git clone '),
            ));

            return new InstallReport($this->walk($clone, $commands));
        } finally {
            $this->tearDown($clone);
        }
    }

    private function cloneInto(string $repository): string
    {
        $clone = $this->temporary.'/clone';
        [$code, $output] = ($this->runner)('git clone -- '.escapeshellarg($repository).' '.escapeshellarg($clone), $this->temporary);

        if (0 !== $code) {
            throw new InstallCheckCouldNotRun('git clone failed: '.$this->tail($output));
        }

        return $clone;
    }

    /**
     * @param list<string> $commands
     *
     * @return list<InstallStepResult>
     */
    private function walk(string $clone, array $commands): array
    {
        $results = [];
        $installed = true;

        foreach ($commands as $command) {
            $result = $installed ? $this->step('install: '.$command, $command, $clone) : $this->skipped('install: '.$command, 'an earlier install command failed');
            $installed = $installed && InstallStatus::Failed !== $result->status;
            $results[] = $result;
        }

        $address = escapeshellarg(self::ADDRESS);
        $node = static fn (string $script, string $arguments): string => 'node '.escapeshellarg($script).' '.$arguments;
        $reason = 'the install failed';

        $results[] = $installed ? $this->step('demo: play the demo run to its verdict', $node($this->checkout.'/bin/install-check-demo', $address.' '.escapeshellarg($this->logs)), $clone) : $this->skipped('demo: play the demo run to its verdict', $reason);
        $results[] = $installed ? $this->step('ui-check: every page', $node($clone.'/bin/ui-check', $address), $clone) : $this->skipped('ui-check: every page', $reason);
        $results[] = match (true) {
            !$installed => $this->skipped('journey-check', $reason),
            !is_file($clone.'/bin/journey-check') => $this->skipped('journey-check', 'the clone has no bin/journey-check'),
            default => $this->step('journey-check', 'bin/journey-check', $clone),
        };
        $results[] = $this->keyedStep($installed, $clone);

        $this->keepScreenshots($clone);

        return $results;
    }

    private function step(string $name, string $command, string $directory): InstallStepResult
    {
        [$code, $output] = ($this->runner)($command, $directory);
        $output = $this->redacted($output);
        $this->files->dumpFile($this->logs.'/'.sprintf('%02d', count($this->found($this->logs.'/*.log')) + 1).'-'.$this->slug($name).'.log', $output);

        return new InstallStepResult($name, 0 === $code ? InstallStatus::Passed : InstallStatus::Failed, $this->tail($output));
    }

    private function skipped(string $name, string $why): InstallStepResult
    {
        return new InstallStepResult($name, InstallStatus::Skipped, $why);
    }

    private function keyedStep(bool $installed, string $clone): InstallStepResult
    {
        $name = 'keyed: review the sample with a provider key';
        $key = array_find(self::KEYS, fn (string $key): bool => '' !== ($this->environment[$key] ?? ''));

        return match (true) {
            null === $key => $this->skipped($name, 'no ANTHROPIC_API_KEY or OPENAI_API_KEY in the environment'),
            !$installed => $this->skipped($name, 'the install failed'),
            // The key travels by name only: docker reads its value from the environment, so it is on no command line.
            default => $this->step($name, 'docker compose exec -T -e '.$key.' intakt php bin/console intakt:demo', $clone),
        };
    }

    private function keepScreenshots(string $clone): void
    {
        foreach ($this->found($clone.'/var/ui-check/*.png') as $picture) {
            $this->files->copy($picture, $this->logs.'/ui-'.basename($picture));
        }
    }

    private function tearDown(?string $clone): void
    {
        if (null !== $clone && is_dir($clone)) {
            ($this->runner)('docker compose down -v --remove-orphans', $clone);
        }

        $this->files->remove($this->temporary.'/clone');
    }

    private function redacted(string $text): string
    {
        foreach (self::KEYS as $key) {
            $value = $this->environment[$key] ?? '';

            if ('' !== $value) {
                $text = str_replace($value, self::REDACTED, $text);
            }
        }

        return $text;
    }

    private function tail(string $output): string
    {
        return implode("\n", array_slice(explode("\n", rtrim($this->redacted($output), "\n")), -self::TAIL_LINES));
    }

    /**
     * @return list<string>
     */
    private function found(string $pattern): array
    {
        $paths = glob($pattern);

        return false === $paths ? [] : $paths;
    }

    private function slug(string $name): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-');
    }
}
