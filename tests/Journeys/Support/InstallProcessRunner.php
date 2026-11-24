<?php

declare(strict_types=1);

namespace Intakt\Tests\Journeys\Support;

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

// A step sees only the variables a shell, docker, git and the browser need, so the README's commands and the clone's scripts cannot read a provider key or a forge token from their environment.
final readonly class InstallProcessRunner
{
    public const int TIMED_OUT = 124;
    private const array PASSED_ON = ['PATH', 'HOME', 'USER', 'LANG', 'LC_ALL', 'TERM', 'TMPDIR', 'XDG_RUNTIME_DIR', 'DOCKER_HOST', 'DOCKER_CONFIG', 'DOCKER_CONTEXT', 'NODE_PATH', 'CHROME_PATH', 'PLAYWRIGHT_BROWSERS_PATH', 'HTTP_PROXY', 'HTTPS_PROXY', 'NO_PROXY', 'http_proxy', 'https_proxy', 'no_proxy'];

    public function __construct(private int $seconds)
    {
    }

    /**
     * @param array<string, string> $environment
     *
     * @return array{0: int, 1: string}
     */
    public function __invoke(string $command, string $directory, array $environment): array
    {
        $process = Process::fromShellCommandline($command, $directory, [...$this->inherited(), ...$environment], null, $this->seconds);

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            return [self::TIMED_OUT, $process->getOutput().$process->getErrorOutput()."\nstopped after ".$this->seconds.' seconds'];
        }

        return [$process->getExitCode() ?? 1, $process->getOutput().$process->getErrorOutput()];
    }

    /**
     * @return array<string, string|false>
     */
    private function inherited(): array
    {
        $parent = getenv();
        $environment = array_map(static fn (): false => false, $parent);

        foreach (self::PASSED_ON as $name) {
            if (isset($parent[$name])) {
                $environment[$name] = $parent[$name];
            }
        }

        return $environment;
    }
}
