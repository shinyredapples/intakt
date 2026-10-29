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
    private const array EMPTY_COMMIT = ['-c', 'user.name=Test', '-c', 'user.email=test@example.invalid', 'commit', '--allow-empty', '--quiet', '-m', 'Empty'];

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
            ['git', '--no-replace-objects', '--literal-pathspecs', '--attr-source=4b825dc642cb6eb9a060e54bf8d69288fbee4904', '-c', 'core.hooksPath=/dev/null', '-c', 'core.fsmonitor=false', '-c', 'diff.external=', '-c', 'core.attributesFile=/dev/null', 'status'],
            $command,
        );
    }

    public function testTheMagicVariantKeepsTheHardeningButAllowsPathspecMagic(): void
    {
        $command = new HardenedGit()->commandWithPathspecMagic(['ls-files']);

        self::assertNotContains('--literal-pathspecs', $command);
        self::assertContains('--no-replace-objects', $command);
        self::assertContains('--attr-source=4b825dc642cb6eb9a060e54bf8d69288fbee4904', $command);
        self::assertContains('core.hooksPath=/dev/null', $command);
    }

    public function testTheEnvironmentRemovesInheritedGitVariablesAndIgnoresUserConfig(): void
    {
        $_ENV['GIT_DIR'] = '/elsewhere';

        $environment = new HardenedGit()->environment($this->repository->path);

        self::assertFalse($environment['GIT_DIR']);
        self::assertSame('1', $environment['GIT_CONFIG_NOSYSTEM']);
        self::assertSame('/dev/null', $environment['GIT_CONFIG_GLOBAL']);
        self::assertSame('/nonexistent', $environment['HOME']);
    }

    public function testTheEnvironmentTurnsReplacementObjectsAndRepositoryAttributesOff(): void
    {
        $_ENV['GIT_NO_REPLACE_OBJECTS'] = '1';
        $_ENV['GIT_ATTR_SOURCE'] = 'HEAD';

        $environment = new HardenedGit()->environment($this->repository->path);

        self::assertSame('1', $environment['GIT_NO_REPLACE_OBJECTS']);
        self::assertSame('4b825dc642cb6eb9a060e54bf8d69288fbee4904', $environment['GIT_ATTR_SOURCE']);
    }

    public function testAHookOfTheRepositoryDoesNotRun(): void
    {
        $marker = $this->repository->path.'/hook-ran';
        $this->repository->writeHook('pre-commit', "#!/bin/sh\ntouch ".$marker."\n");

        $this->runGit(['git', ...self::EMPTY_COMMIT], []);
        self::assertFileExists($marker, 'the hook must run without hardening, or this test proves nothing');
        unlink($marker);

        $this->runHardened(self::EMPTY_COMMIT);
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

        $this->runHardened(['status']);
        self::assertFileDoesNotExist($marker);
    }

    public function testACleanFilterOfTheRepositoryDoesNotRun(): void
    {
        $marker = $this->repository->path.'/.git/clean-ran';
        $this->repository->configure('filter.evil.clean', 'touch '.$marker.'; cat');
        $this->repository->commitFile('.gitattributes', "*.txt filter=evil\n");
        $this->repository->commitFile('note.txt', "base\n");
        file_put_contents($this->repository->path.'/note.txt', "changed\n");
        unlink($marker);

        $this->runGit(['git', 'diff', '--name-only', 'HEAD'], []);
        self::assertFileExists($marker, 'the filter must run without hardening, or this test proves nothing');
        unlink($marker);

        self::assertSame("note.txt\n", $this->runHardened(['diff', '--name-only', 'HEAD']));
        self::assertFileDoesNotExist($marker);
    }

    public function testATextconvDriverThatInfoAttributesNamesDoesNotRunEvenForAToolThatStartsGitItself(): void
    {
        $marker = $this->repository->path.'/.git/textconv-ran';
        $this->repository->commitFile('note.txt', "secret\n");
        $this->repository->configure('diff.evil.textconv', 'touch '.$marker.'; echo hidden; true');
        file_put_contents($this->repository->path.'/.git/info/attributes', "*.txt diff=evil\n");

        $this->runGit(['git', 'log', '-p', '-1'], []);
        self::assertFileExists($marker, 'the driver must run without hardening, or this test proves nothing');
        unlink($marker);

        $patch = $this->runGit(['git', 'log', '-p', '-1'], new HardenedGit()->environment($this->repository->path));
        self::assertFileDoesNotExist($marker);
        self::assertStringContainsString('+secret', $patch);
    }

    /**
     * @param list<string> $arguments
     */
    private function runHardened(array $arguments): string
    {
        return $this->runGit(new HardenedGit()->command($arguments), new HardenedGit()->environment($this->repository->path));
    }

    /**
     * @param list<string>                $command
     * @param array<string, string|false> $environment
     */
    private function runGit(array $command, array $environment): string
    {
        $process = new Process($command, $this->repository->path, $environment);
        $process->run();

        self::assertTrue($process->isSuccessful(), $process->getErrorOutput());

        return $process->getOutput();
    }
}
