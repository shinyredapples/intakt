<?php

declare(strict_types=1);

namespace Intakt\Tests\Journeys\Support;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

#[CoversClass(InstallCheck::class)]
#[CoversClass(InstallProcessRunner::class)]
#[UsesClass(InstallCheckAlarm::class)]
#[UsesClass(InstallReport::class)]
#[UsesClass(InstallStepResult::class)]
#[UsesClass(ReadmeInstallBlock::class)]
#[UsesClass(GitHubIssues::class)]
final class InstallCheckCommandTest extends TestCase
{
    private const string CHECKOUT = __DIR__.'/../../..';
    private const string README = "<!-- install-check -->\n```\ngit clone https://github.com/shinyredapples/intakt.git && cd intakt\n%s\n```\n";
    // Answers like git clone: the README's address is rewritten the way git's url.<base>.insteadOf does, and only a folder with a README clones.
    private const string FAKE_GIT = <<<'PHP'
        <?php
        if (false !== getenv('OPENAI_API_KEY') || false !== getenv('GH_TOKEN')) { touch(__DIR__.'/leaked'); }
        $arguments = array_values(array_filter(array_slice($argv, 2), static fn (string $argument): bool => '--' !== $argument));
        $from = $arguments[0];
        for ($i = 0; $i < (int) getenv('GIT_CONFIG_COUNT'); ++$i) {
            if (getenv('GIT_CONFIG_VALUE_'.$i) === $from) { $from = substr((string) getenv('GIT_CONFIG_KEY_'.$i), 4, -10); }
        }
        if (!is_file($from.'/README.md')) { fwrite(STDERR, "fatal: repository '$from' not found\n"); exit(128); }
        $to = $arguments[1] ?? basename($arguments[0], '.git');
        mkdir($to, 0777, true);
        copy($from.'/README.md', $to.'/README.md');
        PHP;
    private const string FAKE_DOCKER = <<<'PHP'
        <?php
        if (false !== getenv('OPENAI_API_KEY') || false !== getenv('GH_TOKEN')) { touch(__DIR__.'/leaked'); }
        if ('compose' !== $argv[1]) { fwrite(STDERR, "docker: unknown command: docker {$argv[1]}\n"); exit(1); }
        PHP;
    private const string FAKE_NODE = <<<'PHP'
        <?php
        if (false !== getenv('OPENAI_API_KEY') || false !== getenv('GH_TOKEN')) { touch(__DIR__.'/leaked'); }
        PHP;
    private const string FAKE_GH = <<<'PHP'
        <?php
        $arguments = array_slice($argv, 1);
        $input = in_array('--body-file', $arguments, true) ? stream_get_contents(STDIN) : '';
        file_put_contents(__DIR__.'/gh-calls.jsonl', json_encode(['arguments' => $arguments, 'input' => $input])."\n", FILE_APPEND);
        echo 'api' === $arguments[0] ? '[[]]' : ('create' === $arguments[1] ? 'https://github.com/example/repo/issues/7' : '');
        PHP;

    private string $directory = '';

    private string $key = '';

    public function testABrokenReadmeCommandFailsItsStepExitsOneAndFilesItsIssueThroughJourneyAlarm(): void
    {
        $run = $this->installCheck('docker compsoe up -d --wait', ['GITHUB_REPOSITORY' => 'example/repo', 'GH_TOKEN' => 'stand-in-'.bin2hex(random_bytes(8))]);

        self::assertSame(1, $run->getExitCode(), $run->getOutput().$run->getErrorOutput());
        self::assertStringContainsString("passed  install: git clone https://github.com/shinyredapples/intakt.git && cd intakt\n", $run->getOutput());
        self::assertStringContainsString("failed  install: docker compsoe up -d --wait\n    docker: unknown command: docker compsoe\n", $run->getOutput());
        self::assertStringContainsString('filed https://github.com/example/repo/issues/7', $run->getOutput());
        self::assertStringContainsString('docker: unknown command: docker compsoe', (string) file_get_contents($this->directory.'/gh-calls.jsonl'));
        self::assertFileDoesNotExist($this->directory.'/leaked');
        self::assertStringNotContainsString($this->key, $run->getOutput().$run->getErrorOutput());
    }

    public function testAWorkingReadmePassesEveryStepAndARunByHandOnlyPrints(): void
    {
        $run = $this->installCheck('docker compose up -d --wait', []);

        self::assertSame(0, $run->getExitCode(), $run->getOutput().$run->getErrorOutput());
        self::assertStringContainsString("passed  teardown: stop the containers and remove their volume\n", $run->getOutput());
        self::assertStringContainsString('not reported: set GITHUB_REPOSITORY', $run->getOutput());
        self::assertFileDoesNotExist($this->directory.'/gh-calls.jsonl');
        self::assertFileDoesNotExist($this->directory.'/leaked');
    }

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/intakt-install-check-command-'.bin2hex(random_bytes(6));
        $this->key = 'stand-in-'.bin2hex(random_bytes(8));
        $files = new Filesystem();

        foreach (['git' => self::FAKE_GIT, 'docker' => self::FAKE_DOCKER, 'node' => self::FAKE_NODE, 'gh' => self::FAKE_GH] as $name => $script) {
            $files->dumpFile($this->directory.'/'.$name, '#!'.PHP_BINARY."\n".$script);
            $files->chmod($this->directory.'/'.$name, 0o755);
        }
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->directory);
    }

    /**
     * @param array<string, string> $environment
     */
    private function installCheck(string $command, array $environment): Process
    {
        new Filesystem()->dumpFile($this->directory.'/repository/README.md', sprintf(self::README, $command));
        $run = new Process([PHP_BINARY, self::CHECKOUT.'/bin/install-check', $this->directory.'/repository'], self::CHECKOUT, [
            'PATH' => $this->directory.':'.getenv('PATH'),
            'TMPDIR' => $this->directory,
            'OPENAI_API_KEY' => $this->key,
            'ANTHROPIC_API_KEY' => false,
            'GH_TOKEN' => false,
            'GITHUB_TOKEN' => false,
            'GITHUB_REPOSITORY' => false,
            'GITHUB_RUN_ID' => false,
            ...$environment,
        ], null, 60);
        $run->run();

        if (1 === preg_match('/^logs and screenshots in (var\/install-check\/\S+)$/m', $run->getOutput(), $logs)) {
            new Filesystem()->remove(self::CHECKOUT.'/'.$logs[1]);
        }

        return $run;
    }
}
