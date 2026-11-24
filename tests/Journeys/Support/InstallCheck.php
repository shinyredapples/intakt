<?php

declare(strict_types=1);

namespace Intakt\Tests\Journeys\Support;

use Closure;
use Intakt\Plugin\LocalGitForge\UseCase\ReviewChange\ReviewExit;
use Symfony\Component\Filesystem\Filesystem;

// The README's own clone command runs verbatim, so a broken address or folder in it fails; a git rewrite points the README's address at the repository under check.
final readonly class InstallCheck
{
    public const string ADDRESS = 'http://localhost:7781';
    public const string REPOSITORY = 'https://github.com/shinyredapples/intakt.git';
    public const array KEYS = ['ANTHROPIC_API_KEY', 'OPENAI_API_KEY'];
    private const int TAIL_LINES = 40;
    private const string REDACTED = '[redacted]';
    private const string TEARDOWN = 'teardown: stop the containers and remove their volume';
    // What .env.local can hold unquoted, so the key reaches the container as written.
    private const string KEY_SHAPE = '/\A[A-Za-z0-9._~+\/=-]+\z/';

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
        $environment = $this->environmentFor($repository);
        $this->files->mkdir($this->logs);

        try {
            [$code] = ($this->runner)('docker compose version', $this->temporary, $environment);

            if (0 !== $code) {
                throw new InstallCheckCouldNotRun('docker compose is not available, so the install cannot be tried');
            }

            $commands = $this->commandsOf($repository, $environment);
            $home = $this->temporary.'/home';
            $this->files->mkdir($home);

            try {
                $results = $this->walk($home, $commands, $environment);
            } finally {
                $teardown = $this->tearDown($home, $environment);
            }

            return new InstallReport([...$results, $teardown]);
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
     * @param array<string, string> $environment
     *
     * @return list<string>
     */
    private function commandsOf(string $repository, array $environment): array
    {
        $source = $this->temporary.'/source';
        [$code, $output] = ($this->runner)('git clone -- '.escapeshellarg($repository).' '.escapeshellarg($source), $this->temporary, $environment);

        if (0 !== $code) {
            throw new InstallCheckCouldNotRun('git clone failed: '.$this->tail($output));
        }

        $readme = @file_get_contents($source.'/README.md');

        return ReadmeInstallBlock::commandsIn(false === $readme ? throw new InstallCheckCouldNotRun('the clone has no readable README.md') : $readme);
    }

    /**
     * @param list<string>          $commands
     * @param array<string, string> $environment
     *
     * @return list<InstallStepResult>
     */
    private function walk(string $home, array $commands, array $environment): array
    {
        $results = [];
        $installed = true;

        foreach ($commands as $command) {
            $result = $installed ? $this->step('install: '.$command, $command, $this->cloneIn($home), $environment) : $this->skipped('install: '.$command, 'an earlier install command failed');
            $installed = $installed && InstallStatus::Failed !== $result->status;
            $results[] = $result;
        }

        $clone = $this->cloneIn($home);
        $address = escapeshellarg(self::ADDRESS);
        $reason = 'the install failed';

        $results[] = $installed ? $this->step('demo: play the demo run to its verdict', 'node '.escapeshellarg($this->checkout.'/bin/install-check-demo').' '.$address.' '.escapeshellarg($this->logs), $clone, $environment) : $this->skipped('demo: play the demo run to its verdict', $reason);
        $results[] = $installed ? $this->step('ui-check: every page', 'node bin/ui-check '.$address, $clone, $environment) : $this->skipped('ui-check: every page', $reason);
        $results[] = match (true) {
            !$installed => $this->skipped('journey-check', $reason),
            !is_file($clone.'/bin/journey-check') => $this->skipped('journey-check', 'the clone has no bin/journey-check'),
            default => $this->step('journey-check', 'node bin/journey-check '.$address, $clone, $environment),
        };
        $results[] = $this->keyedStep($installed, $clone, $environment);

        $this->keepScreenshots($clone);

        return $results;
    }

    // The README's clone command makes the one folder the later commands run in; before it, they run in the empty home.
    private function cloneIn(string $home): string
    {
        $folders = $this->found($home.'/*', GLOB_ONLYDIR);

        return 1 === count($folders) ? $folders[0] : $home;
    }

    /**
     * @param array<string, string> $environment
     * @param list<int>             $passing
     */
    private function step(string $name, string $command, string $directory, array $environment, array $passing = [0]): InstallStepResult
    {
        [$code, $output] = ($this->runner)($command, $directory, $environment);
        $output = $this->redacted($output);
        $this->files->dumpFile($this->logs.'/'.sprintf('%02d', count($this->found($this->logs.'/*.log')) + 1).'-'.$this->slug($name).'.log', $output);

        return new InstallStepResult($name, in_array($code, $passing, true) ? InstallStatus::Passed : InstallStatus::Failed, $this->tail($output));
    }

    private function skipped(string $name, string $why): InstallStepResult
    {
        return new InstallStepResult($name, InstallStatus::Skipped, $why);
    }

    /**
     * @param array<string, string> $environment
     */
    private function keyedStep(bool $installed, string $clone, array $environment): InstallStepResult
    {
        $name = 'keyed: review the sample with a provider key';
        $key = array_find(self::KEYS, fn (string $key): bool => '' !== ($this->keys[$key] ?? ''));

        if (null === $key) {
            return $this->skipped($name, 'no ANTHROPIC_API_KEY or OPENAI_API_KEY in the environment');
        }

        if (!$installed) {
            return $this->skipped($name, 'the install failed');
        }

        $value = $this->keys[$key] ?? '';

        if (1 !== preg_match(self::KEY_SHAPE, $value)) {
            return new InstallStepResult($name, InstallStatus::Failed, $key.' holds characters .env.local cannot keep unquoted');
        }

        // The container seeds the sample's reviewers from the keys it sees at start, so the key goes to .env.local, as the README says, and the container starts again; the key is on no command line.
        $this->files->dumpFile($clone.'/.env.local', $key.'='.$value."\n");
        $this->files->chmod($clone.'/.env.local', 0o600);
        $verdicts = array_map(static fn (ReviewExit $exit): int => $exit->value, ReviewExit::cases());

        return $this->step($name, 'docker compose up -d --wait && docker compose exec -T intakt php bin/console intakt:demo', $clone, $environment, $verdicts);
    }

    private function keepScreenshots(string $clone): void
    {
        foreach ([...$this->found($clone.'/var/ui-check/*.png'), ...$this->found($clone.'/var/journey-check/*/*.png')] as $picture) {
            $this->files->copy($picture, $this->logs.'/'.basename(dirname($picture)).'-'.basename($picture));
        }
    }

    /**
     * @param array<string, string> $environment
     */
    private function tearDown(string $home, array $environment): InstallStepResult
    {
        $clone = $this->cloneIn($home);

        return $clone === $home ? $this->skipped(self::TEARDOWN, 'no clone was made') : $this->step(self::TEARDOWN, 'docker compose down -v --remove-orphans', $clone, $environment);
    }

    private function redacted(string $text): string
    {
        foreach (self::KEYS as $key) {
            $value = $this->keys[$key] ?? '';

            if ('' !== $value) {
                $text = str_replace($value, self::REDACTED, $text);
            }
        }

        // intakt:demo prints the MCP owner token in its connect line, and the log goes into an artifact and an issue.
        return (string) preg_replace('/(Bearer\s+)[^\s"\']+/', '$1'.self::REDACTED, $text);
    }

    private function tail(string $output): string
    {
        return implode("\n", array_slice(explode("\n", rtrim($this->redacted($output), "\n")), -self::TAIL_LINES));
    }

    /**
     * @return list<string>
     */
    private function found(string $pattern, int $flags = 0): array
    {
        $paths = glob($pattern, $flags);

        return false === $paths ? [] : $paths;
    }

    private function slug(string $name): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-');
    }
}
