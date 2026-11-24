<?php

declare(strict_types=1);

namespace Intakt\Tests\Journeys\Support;

use Closure;
use Symfony\Component\Filesystem\Filesystem;

// The README's own clone command runs verbatim, so a broken address or folder in it fails; a git rewrite points the README's address at the repository under check.
final readonly class InstallCheck
{
    public const string ADDRESS = 'http://localhost:7781';
    public const string REPOSITORY = 'https://github.com/shinyredapples/intakt.git';
    public const array KEYS = ['ANTHROPIC_API_KEY', 'OPENAI_API_KEY'];

    // The runner takes a shell command, a folder and the variables the step adds, and answers the exit code and the output, so a test needs no docker.
    /**
     * @param Closure(string, string, array<string, string>): array{0: int, 1: string} $runner
     * @param array<string, string>                                                    $keys
     */
    public function __construct(
        private Closure $runner,
        private string $temporary,
        private string $logs,
        private string $checkout,
        private array $keys = [],
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

        $repository = is_dir($repository) ? (string) realpath($repository) : $repository;
        $walk = new InstallWalk($this->runner, $this->environmentFor($repository), $this->logs, $this->checkout, $this->keys, $this->files);
        $this->files->mkdir($this->logs);

        try {
            [$code] = $walk->command('docker compose version', $this->temporary);

            if (0 !== $code) {
                throw new InstallCheckCouldNotRun('docker compose is not available, so the install cannot be tried');
            }

            $commands = $this->commandsOf($repository, $walk);
            $home = $this->temporary.'/home';
            $this->files->mkdir($home);

            return $walk->through($home, $commands);
        } finally {
            $this->files->remove([$this->temporary.'/source', $this->temporary.'/home']);
        }
    }

    /**
     * @return array<string, string>
     */
    private function environmentFor(string $repository): array
    {
        // Its own project name, so two runs on one docker never stop each other's containers or remove each other's volume.
        $environment = ['COMPOSE_PROJECT_NAME' => 'intakt-install-check-'.bin2hex(random_bytes(6)), 'GIT_TERMINAL_PROMPT' => '0'];

        return self::REPOSITORY === $repository ? $environment : [...$environment, 'GIT_CONFIG_COUNT' => '1', 'GIT_CONFIG_KEY_0' => 'url.'.$repository.'.insteadOf', 'GIT_CONFIG_VALUE_0' => self::REPOSITORY];
    }

    /**
     * @return list<string>
     */
    private function commandsOf(string $repository, InstallWalk $walk): array
    {
        $source = $this->temporary.'/source';
        [$code, $output] = $walk->command('git clone -- '.escapeshellarg($repository).' '.escapeshellarg($source), $this->temporary);

        if (0 !== $code) {
            throw new InstallCheckCouldNotRun('git clone failed: '.$walk->tail($output));
        }

        $readme = @file_get_contents($source.'/README.md');

        return ReadmeInstallBlock::commandsIn(false === $readme ? throw new InstallCheckCouldNotRun('the clone has no readable README.md') : $readme);
    }
}
