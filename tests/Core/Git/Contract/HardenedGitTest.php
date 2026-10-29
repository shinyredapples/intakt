<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Git\Contract;

use Intakt\Core\Git\Contract\HardenedGit;
use Intakt\Tests\Plugin\LocalGitForge\TemporaryRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

#[CoversClass(HardenedGit::class)]
final class HardenedGitTest extends TestCase
{
    private TemporaryRepository $repository;

    /** @var array<array-key, mixed> */
    private array $environment;

    protected function setUp(): void
    {
        $this->environment = $_ENV;
        $this->repository = new TemporaryRepository();
        $this->repository->commitFile('README.md', "base\n");
    }

    protected function tearDown(): void
    {
        $_ENV = $this->environment;
        $this->repository->remove();
    }

    public function testTheCommandDisablesHooksFsmonitorExternalDiffAndAttributes(): void
    {
        $command = new HardenedGit()->command(['status']);

        self::assertSame(
            ['git', '--no-replace-objects', '--literal-pathspecs', '-c', 'core.hooksPath=/dev/null', '-c', 'core.fsmonitor=false', '-c', 'diff.external=', '-c', 'core.attributesFile=/dev/null', 'status'],
            $command,
        );
    }

    public function testTheMagicVariantKeepsTheHardeningButAllowsPathspecMagic(): void
    {
        $command = new HardenedGit()->commandWithPathspecMagic(['ls-files']);

        self::assertNotContains('--literal-pathspecs', $command);
        self::assertContains('--no-replace-objects', $command);
        self::assertContains('core.hooksPath=/dev/null', $command);
    }

    public function testTheEnvironmentRemovesInheritedGitVariablesAndIgnoresUserConfig(): void
    {
        $_ENV['GIT_DIR'] = '/elsewhere';

        $environment = new HardenedGit()->environment();

        self::assertFalse($environment['GIT_DIR']);
        self::assertSame('1', $environment['GIT_CONFIG_NOSYSTEM']);
        self::assertSame('/dev/null', $environment['GIT_CONFIG_GLOBAL']);
        self::assertSame('/nonexistent', $environment['HOME']);
    }

    public function testAHookOfTheRepositoryDoesNotRun(): void
    {
        $marker = $this->repository->path.'/hook-ran';
        $this->repository->writeHook('pre-commit', "#!/bin/sh\ntouch ".$marker."\n");

        $this->commitEmpty(hardened: false);
        self::assertFileExists($marker, 'the hook must run without hardening, or this test proves nothing');
        unlink($marker);

        $this->commitEmpty(hardened: true);
        self::assertFileDoesNotExist($marker);
    }

    public function testAnFsmonitorCommandOfTheRepositoryDoesNotRun(): void
    {
        $marker = $this->repository->path.'/fsmonitor-ran';
        $script = $this->repository->writeHook('fsmonitor-script', "#!/bin/sh\ntouch ".$marker."\n");
        $this->repository->configure('core.fsmonitor', $script);

        $this->runGit(['git', 'status'], []);
        self::assertFileExists($marker, 'the command must run without hardening, or this test proves nothing');
        unlink($marker);

        $this->runGit(new HardenedGit()->command(['status']), new HardenedGit()->environment());
        self::assertFileDoesNotExist($marker);
    }

    private function commitEmpty(bool $hardened): void
    {
        $arguments = ['-c', 'user.name=Test', '-c', 'user.email=test@example.invalid', 'commit', '--allow-empty', '--quiet', '-m', 'Empty'];
        $command = $hardened ? new HardenedGit()->command($arguments) : ['git', ...$arguments];

        $this->runGit($command, $hardened ? new HardenedGit()->environment() : []);
    }

    /**
     * @param list<string>                $command
     * @param array<string, string|false> $environment
     */
    private function runGit(array $command, array $environment): void
    {
        $process = new Process($command, $this->repository->path, $environment);
        $process->run();

        self::assertTrue($process->isSuccessful(), $process->getErrorOutput());
    }
}
