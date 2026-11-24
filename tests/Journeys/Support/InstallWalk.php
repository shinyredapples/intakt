<?php

declare(strict_types=1);

namespace Intakt\Tests\Journeys\Support;

use Closure;
use Intakt\Plugin\LocalGitForge\UseCase\ReviewChange\ReviewExit;
use Symfony\Component\Filesystem\Filesystem;

// One run's steps share its compose project and git rewrite, so they live in an object made for that run.
final readonly class InstallWalk
{
    private const int TAIL_LINES = 40;
    private const string REDACTED = '[redacted]';
    private const string TEARDOWN = 'teardown: stop the containers and remove their volume';
    private const string DEMO = 'demo: play the demo run to its verdict';
    private const string UI = 'ui-check: every page';
    private const string JOURNEYS = 'journey-check';
    private const string KEYED = 'keyed: review the sample with a provider key';
    private const string ENDED_IN = 'INTAKT_INSTALL_CHECK_ENDED_IN';
    private const string ENDED_IN_FILE = '.install-check-ended-in';
    // Keeps the last folder inside the clone's home that a command ended in, because teardown runs there and finds the containers through Compose's lookup of its file in parent folders and the run's own COMPOSE_PROJECT_NAME.
    private const string LAST_FOLDER_IN_HOME_FILE = '.install-check-last-folder-in-home';
    // Only what .env.local can hold unquoted, because a quote or a space would change the key the container reads.
    private const string KEY_SHAPE = '/\A[A-Za-z0-9._~+\/=-]+\z/';

    /**
     * @param Closure(string, string, array<string, string>): array{0: int, 1: string} $runner
     * @param array<string, string>                                                    $environment
     * @param array<string, string>                                                    $keys
     */
    public function __construct(
        private Closure $runner,
        private array $environment,
        private string $logs,
        private string $checkout,
        private array $keys,
        private Filesystem $files,
    ) {
    }

    /**
     * @param list<string> $commands
     */
    public function through(string $home, array $commands): InstallReport
    {
        try {
            $results = $this->walk($home, $commands);
        } finally {
            $teardown = $this->tearDown($home);
        }

        return new InstallReport([...$results, $teardown]);
    }

    /**
     * @return array{0: int, 1: string}
     */
    public function command(string $command, string $directory): array
    {
        return ($this->runner)($command, $directory, $this->environment);
    }

    public function tail(string $output): string
    {
        return implode("\n", array_slice(explode("\n", rtrim($this->redacted($output), "\n")), -self::TAIL_LINES));
    }

    /**
     * @param list<string> $commands
     *
     * @return list<InstallStepResult>
     */
    private function walk(string $home, array $commands): array
    {
        $results = [];
        $installed = true;

        foreach ($commands as $command) {
            $result = $installed ? $this->installStep($command, $home) : $this->skipped('install: '.$command, 'an earlier install command failed');
            $installed = $installed && InstallStatus::Failed !== $result->status;
            $results[] = $result;
        }

        if (!$installed) {
            return [...$results, ...array_map(fn (string $name): InstallStepResult => $this->skipped($name, 'the install failed'), [self::DEMO, self::UI, self::JOURNEYS]), $this->keyedStep(false, $home)];
        }

        $clone = $this->cloneIn($home);
        $address = escapeshellarg(InstallCheck::ADDRESS);
        $results[] = $this->step(self::DEMO, 'node '.escapeshellarg($this->checkout.'/bin/install-check-demo').' '.$address.' '.escapeshellarg($this->logs), $clone);
        $results[] = $this->step(self::UI, 'node bin/ui-check '.$address, $clone);
        $results[] = is_file($clone.'/bin/journey-check') ? $this->step(self::JOURNEYS, 'node bin/journey-check '.$address, $clone) : $this->skipped(self::JOURNEYS, 'the clone has no bin/journey-check');
        $results[] = $this->keyedStep(true, $clone);
        $this->keepScreenshots($clone);

        return $results;
    }

    // Each README line is its own shell, so a cd in one would be lost for the next: the shell writes where it ended, and the next line starts there.
    private function installStep(string $command, string $home): InstallStepResult
    {
        $directory = $this->cloneIn($home);
        $script = $command."\n".'intakt_status=$?; pwd > "$'.self::ENDED_IN.'"; exit "$intakt_status"';

        $result = $this->recorded('install: '.$command, ($this->runner)($script, $directory, [...$this->environment, self::ENDED_IN => $home.'/'.self::ENDED_IN_FILE]), [0]);
        $ended = $this->cloneIn($home);

        // A later line that returns to home must not move teardown away from where Compose started, so the last folder inside home is kept apart.
        if ($ended !== $home) {
            $this->files->dumpFile($home.'/'.self::LAST_FOLDER_IN_HOME_FILE, (string) realpath($ended));
        }

        return $result;
    }

    private function skipped(string $name, string $why): InstallStepResult
    {
        return new InstallStepResult($name, InstallStatus::Skipped, $why);
    }

    private function keyedStep(bool $installed, string $clone): InstallStepResult
    {
        $key = array_find(InstallCheck::KEYS, fn (string $key): bool => '' !== ($this->keys[$key] ?? ''));

        if (null === $key) {
            return $this->skipped(self::KEYED, 'no ANTHROPIC_API_KEY or OPENAI_API_KEY in the environment');
        }

        if (!$installed) {
            return $this->skipped(self::KEYED, 'the install failed');
        }

        $value = $this->keys[$key] ?? '';

        if (1 !== preg_match(self::KEY_SHAPE, $value)) {
            return new InstallStepResult(self::KEYED, InstallStatus::Failed, $key.' holds characters .env.local cannot keep unquoted');
        }

        // The container seeds the sample's reviewers from the keys it sees at start, so the key goes to .env.local, as the README says, and the container starts again; the key is on no command line.
        $this->files->dumpFile($clone.'/.env.local', $key.'='.$value."\n");
        $this->files->chmod($clone.'/.env.local', 0o600);
        $verdicts = array_map(static fn (ReviewExit $exit): int => $exit->value, ReviewExit::cases());

        return $this->recorded(self::KEYED, $this->command('docker compose up -d --wait && docker compose exec -T intakt php bin/console intakt:demo', $clone), $verdicts);
    }

    // Only a folder inside home counts, so a README line that ends elsewhere, or none that ran, leaves the commands in home.
    private function cloneIn(string $home, string $file = self::ENDED_IN_FILE): string
    {
        $root = realpath($home);
        $ended = realpath(trim((string) @file_get_contents($home.'/'.$file)));

        if (false === $root || false === $ended || $ended === $root || !str_starts_with($ended, $root.'/') || !is_dir($ended)) {
            return $home;
        }

        return $home.substr($ended, strlen($root));
    }

    private function step(string $name, string $command, string $directory): InstallStepResult
    {
        return $this->recorded($name, $this->command($command, $directory), [0]);
    }

    /**
     * @param array{0: int, 1: string} $outcome
     * @param list<int>                $passing
     */
    private function recorded(string $name, array $outcome, array $passing): InstallStepResult
    {
        [$code, $output] = $outcome;
        $output = $this->redacted($output);
        $this->files->dumpFile($this->logs.'/'.sprintf('%02d', count($this->found($this->logs.'/*.log')) + 1).'-'.$this->slug($name).'.log', $output);

        return new InstallStepResult($name, in_array($code, $passing, true) ? InstallStatus::Passed : InstallStatus::Failed, $this->tail($output));
    }

    private function redacted(string $text): string
    {
        foreach (InstallCheck::KEYS as $key) {
            $value = $this->keys[$key] ?? '';

            if ('' !== $value) {
                $text = str_replace($value, self::REDACTED, $text);
            }
        }

        // Because intakt:demo prints the MCP owner token in its connect line, and the log goes into an artifact and an issue.
        return (string) preg_replace('/(Bearer\s+)[^\s"\']+/', '$1'.self::REDACTED, $text);
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

    // The clone is removed with the temporary folder, so its screenshots are copied to the logs first.
    private function keepScreenshots(string $clone): void
    {
        foreach ([...$this->found($clone.'/var/ui-check/*.png'), ...$this->found($clone.'/var/journey-check/*/*.png')] as $picture) {
            $this->files->copy($picture, $this->logs.'/'.basename(dirname($picture)).'-'.basename($picture));
        }
    }

    private function tearDown(string $home): InstallStepResult
    {
        $clone = $this->cloneIn($home, self::LAST_FOLDER_IN_HOME_FILE);

        return $clone === $home ? $this->skipped(self::TEARDOWN, 'no clone was made') : $this->step(self::TEARDOWN, 'docker compose down -v --remove-orphans', $clone);
    }
}
