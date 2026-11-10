<?php

declare(strict_types=1);

namespace Intakt\Tests\Application;

use Intakt\Tests\Support\ProjectCopy;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

abstract class InstanceTestCase extends TestCase
{
    protected const string ROOT = __DIR__.'/../..';

    // An instance per test, not per class, because each boot builds its container in a scratch folder it removes when it stops, which is what several tests prove, and most tests boot with another project, command or environment.
    protected string $scratch;

    public static function tearDownAfterClass(): void
    {
        new Filesystem()->remove(self::sharedProject());
    }

    protected function setUp(): void
    {
        $this->scratch = sys_get_temp_dir().'/intakt-instance-test-'.bin2hex(random_bytes(6));
        new Filesystem()->mkdir($this->scratch.'/tmp');
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->scratch);
    }

    /**
     * @param list<string>                $command
     * @param array<string, string|false> $environment
     *
     * @return array{status: string, exit: int, output: string, errors: string}
     */
    protected function runInstance(string $project, array $command = [], array $environment = []): array
    {
        $statusFile = $this->scratch.'/status';
        $arguments = [] === $command ? [] : ['--', ...$command];
        $shell = 'exec '.implode(' ', array_map(escapeshellarg(...), [PHP_BINARY, $project.'/bin/instance', ...$arguments])).' 3>'.escapeshellarg($statusFile);
        $process = Process::fromShellCommandline($shell, null, [...$this->environment(), ...$environment]);
        $process->run();

        return [
            'status' => trim((string) file_get_contents($statusFile)),
            'exit' => (int) $process->getExitCode(),
            'output' => $process->getOutput(),
            'errors' => $process->getErrorOutput(),
        ];
    }

    /**
     * @return array{status: string, reason: string}
     */
    protected function decoded(string $statusLine): array
    {
        $status = json_decode($statusLine, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($status);
        self::assertIsString($status['status'] ?? null);
        self::assertIsString($status['reason'] ?? null);

        return ['status' => $status['status'], 'reason' => $status['reason']];
    }

    /**
     * @return array<string, string>
     */
    protected function environment(): array
    {
        // Without frankenphp on the PATH, so these runs cover the built-in server wherever they run.
        return ['TMPDIR' => $this->scratch.'/tmp', 'APP_ENV' => 'test', 'PATH' => $this->pathWithoutFrankenphp()];
    }

    protected function pathWithoutFrankenphp(): string
    {
        $directories = array_filter(explode(':', (string) getenv('PATH')), static fn (string $directory): bool => !is_file($directory.'/frankenphp'));

        return implode(':', $directories);
    }

    protected function copyOfTheProject(): string
    {
        $project = self::sharedProject();
        new Filesystem()->mkdir($project);
        ProjectCopy::refresh(self::ROOT, $project, 'bin', 'src', 'config', 'public', 'composer.json', '.env');
        new Filesystem()->remove($project.'/config/reference.php');

        return $project;
    }

    /**
     * @return list<string>
     */
    protected function matching(string $pattern): array
    {
        $paths = glob($pattern);

        return false === $paths ? [] : $paths;
    }

    /**
     * @return list<string>
     */
    protected function entriesOf(string $directory): array
    {
        $found = scandir($directory);

        return array_values(array_diff(false === $found ? [] : $found, ['.', '..']));
    }

    // Named by the process, because the copy outlives a test while two runs of these classes side by side must not share it.
    private static function sharedProject(): string
    {
        return sys_get_temp_dir().'/intakt-instance-test-project-'.getmypid();
    }
}
