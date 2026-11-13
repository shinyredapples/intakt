<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review;

use Intakt\Core\Ai\Infrastructure\IsolatedCodexCli;
use Intakt\Core\Job\Infrastructure\EgressProxyDefinition;
use Intakt\Tests\Core\Git\TemporaryRepository;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

// The script runs inside the job's container, so it is tested by running it with sh -c as the container does, with a scripted CLI in place of the vendor's.
#[CoversNothing]
final class ReviewScriptTest extends TestCase
{
    private const string SCRIPT = __DIR__.'/../../../docker/job/review.sh';
    private const string PROXY = 'http://egress-proxy:3128';

    private TemporaryRepository $repository;
    private ?TemporaryRepository $otherRepository = null;
    private string $folder = '';
    private string $head = '';
    private string $cli = 'claude';
    private ?string $headArgument = null;
    private ?string $checkout = null;

    protected function setUp(): void
    {
        $this->repository = new TemporaryRepository();
        $this->head = $this->repository->commitFile('src/Change.php', "<?php\n");
        $this->folder = sys_get_temp_dir().'/intakt-review-job-'.bin2hex(random_bytes(6));
        $filesystem = new Filesystem();
        $filesystem->mkdir([$this->folder.'/output', $this->folder.'/work', $this->folder.'/bin']);
        $filesystem->dumpFile($this->folder.'/prompt.md', "Review the change.\n");
        foreach (['claude', 'codex'] as $cli) {
            $filesystem->dumpFile($this->folder.'/bin/'.$cli, "#!/bin/sh\nprintf '%s\\n' \"\$@\" > \"\$RECORD/arguments\"\nenv > \"\$RECORD/environment\"\npwd > \"\$RECORD/folder\"\ncat\n");
            $filesystem->chmod($this->folder.'/bin/'.$cli, 0o755);
        }
    }

    protected function tearDown(): void
    {
        new Process(['chmod', '-R', 'u+w', $this->repository->path])->run();
        $this->repository->remove();
        $this->otherRepository?->remove();
        new Filesystem()->remove($this->folder);
    }

    public function testTheScriptsProxyIsTheOneEveryJobWithAnAllowlistIsGiven(): void
    {
        self::assertSame(sprintf('http://%s:%d', EgressProxyDefinition::ALIAS, EgressProxyDefinition::PORT), self::PROXY);
        self::assertStringContainsString(sprintf("proxy='%s'", self::PROXY), (string) file_get_contents(self::SCRIPT));
    }

    public function testAReadOnlyCheckoutAtTheHeadIsReviewedWithThePromptOnTheClisInput(): void
    {
        $this->givenTheCheckoutIsReadOnly();

        $run = $this->whenTheReviewRuns();

        self::assertSame(0, $run->getExitCode(), $this->outputFile('review.log'));
        self::assertSame("Review the change.\n", $this->outputFile('answer'));
        self::assertSame($this->repository->path, trim($this->recorded('folder')));
        self::assertStringContainsString("claude exited with 0\n", $this->outputFile('review.log'));
    }

    public function testClaudeGetsTheModelAndOnlyItsReadOnlyFileTools(): void
    {
        $this->givenTheCheckoutIsReadOnly();

        $this->whenTheReviewRuns();

        self::assertSame(
            ['-p', '--model', 'claude-opus-5-5', '--output-format', 'json', '--tools', 'Read,Glob,Grep', '--setting-sources', '', '--strict-mcp-config', '--no-session-persistence'],
            explode("\n", substr($this->recorded('arguments'), 0, -1)),
        );
    }

    public function testCodexGetsTheModelItsReadOnlySandboxAndEveryToolOfTheInProcessReviewerSwitchedOff(): void
    {
        $this->givenTheCheckoutIsReadOnly();
        $this->cli = 'codex';

        $run = $this->whenTheReviewRuns();

        self::assertSame(0, $run->getExitCode(), $this->outputFile('review.log'));
        self::assertSame(
            [
                'exec', '--json', '--model', 'claude-opus-5-5', '--sandbox', 'read-only', '--ephemeral', '--skip-git-repo-check', '--ignore-user-config', '--ignore-rules',
                ...array_merge(...array_map(static fn (string $feature): array => ['--disable', $feature], IsolatedCodexCli::DISABLED_FEATURES)),
                '-c', 'web_search="disabled"', '-',
            ],
            explode("\n", trim($this->recorded('arguments'))),
        );
    }

    public function testTheCliFindsNoProxyExceptionAnImageSet(): void
    {
        $this->givenTheCheckoutIsReadOnly();

        $this->whenTheReviewRuns(['NO_PROXY' => 'api.anthropic.com', 'no_proxy' => '*', 'ALL_PROXY' => 'socks5://outside.example:1080']);

        self::assertDoesNotMatchRegularExpression('/^(NO_PROXY|no_proxy|ALL_PROXY|all_proxy)=/m', $this->recorded('environment'));
        self::assertMatchesRegularExpression('/^HTTPS_PROXY=http:\/\/egress-proxy:3128$/m', $this->recorded('environment'));
    }

    public function testACheckoutAtAnotherCommitIsNotReviewed(): void
    {
        $this->repository->commitFile('src/Later.php', "<?php\n");
        $this->givenTheCheckoutIsReadOnly();

        $run = $this->whenTheReviewRuns();

        self::assertSame(1, $run->getExitCode());
        self::assertStringContainsString(sprintf(', not at %s', $this->head), $this->outputFile('review.log'));
        self::assertTheCliDidNotRun();
    }

    // An image's GIT_DIR and GIT_WORK_TREE would send every git call to the other repository, whose head is the one the review was told.
    public function testACheckoutAtAnotherCommitIsNotReviewedWhenTheImagesGitVariablesNameAnotherRepositoryAtTheHead(): void
    {
        $this->otherRepository = $this->repository->copy();
        $this->repository->commitFile('src/Later.php', "<?php\n");
        $this->givenTheCheckoutIsReadOnly();

        $run = $this->whenTheReviewRuns(['GIT_DIR' => $this->otherRepository->path.'/.git', 'GIT_WORK_TREE' => $this->otherRepository->path]);

        self::assertSame(1, $run->getExitCode(), $this->outputFile('review.log'));
        self::assertStringContainsString(sprintf(', not at %s', $this->head), $this->outputFile('review.log'));
        self::assertTheCliDidNotRun();
    }

    public function testTheCliFindsNoGitVariableAndNoNodeOptionsAnImageSet(): void
    {
        $this->givenTheCheckoutIsReadOnly();

        $this->whenTheReviewRuns(['GIT_DIR' => $this->repository->path.'/.git', 'GIT_SSH_COMMAND' => 'planted', 'NODE_OPTIONS' => '--require /planted.js']);

        preg_match_all('/^(GIT_|NODE_OPTIONS=).*$/m', $this->recorded('environment'), $gitLines);
        sort($gitLines[0]);
        self::assertSame(['GIT_CONFIG_GLOBAL=/dev/null', 'GIT_CONFIG_NOSYSTEM=1'], $gitLines[0]);
    }

    #[DataProvider('notFullCommitNames')]
    public function testAHeadThatIsNoFullCommitNameIsNotReviewed(string $head): void
    {
        $this->givenTheCheckoutIsReadOnly();
        $this->headArgument = $head;

        $run = $this->whenTheReviewRuns();

        self::assertSame(1, $run->getExitCode());
        self::assertStringContainsString('is not a full commit name', $this->outputFile('review.log'));
        self::assertTheCliDidNotRun();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function notFullCommitNames(): iterable
    {
        yield 'a branch' => ['HEAD'];
        yield 'a short name' => ['0123456'];
        yield 'uppercase' => [str_repeat('A', 40)];
        yield 'one digit short' => [str_repeat('a', 39)];
    }

    public function testACheckoutWithAChangedFileIsNotReviewed(): void
    {
        file_put_contents($this->repository->path.'/src/Change.php', "<?php\n// changed\n");
        $this->givenTheCheckoutIsReadOnly();

        $run = $this->whenTheReviewRuns();

        self::assertSame(1, $run->getExitCode());
        self::assertStringContainsString(sprintf('the checkout differs from %s', $this->head), $this->outputFile('review.log'));
        self::assertTheCliDidNotRun();
    }

    #[DataProvider('marksGitStatusTrusts')]
    public function testACheckoutWithAChangedFileThatGitStatusIsToldToSkipIsNotReviewed(string $mark): void
    {
        file_put_contents($this->repository->path.'/src/Change.php', "<?php\n// changed\n");
        new Process(['git', 'update-index', $mark, 'src/Change.php'], $this->repository->path)->mustRun();
        $this->givenTheCheckoutIsReadOnly();

        $run = $this->whenTheReviewRuns();

        self::assertSame(1, $run->getExitCode());
        self::assertStringContainsString('src/Change.php is marked so that git status does not compare it', $this->outputFile('review.log'));
        self::assertTheCliDidNotRun();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function marksGitStatusTrusts(): iterable
    {
        yield 'skip-worktree' => ['--skip-worktree'];
        yield 'assume-unchanged' => ['--assume-unchanged'];
    }

    public function testACheckoutWithAnUntrackedFileIsNotReviewed(): void
    {
        file_put_contents($this->repository->path.'/src/Planted.php', "<?php\n");
        $this->givenTheCheckoutIsReadOnly();

        $run = $this->whenTheReviewRuns();

        self::assertSame(1, $run->getExitCode());
        self::assertTheCliDidNotRun();
    }

    public function testAFolderWithoutItsOwnRepositoryIsNotReviewed(): void
    {
        $this->checkout = $this->repository->path.'/src';

        $run = $this->whenTheReviewRuns();

        self::assertSame(1, $run->getExitCode());
        self::assertStringContainsString('holds no repository', $this->outputFile('review.log'));
        self::assertTheCliDidNotRun();
    }

    public function testAWritableCheckoutIsNotReviewed(): void
    {
        $run = $this->whenTheReviewRuns();

        self::assertSame(2, $run->getExitCode());
        self::assertStringContainsString('can be written, so the checkout is not read-only', $this->outputFile('review.log'));
        self::assertFileDoesNotExist($this->repository->path.'/.intakt-review-probe');
        self::assertTheCliDidNotRun();
    }

    public function testACheckoutWhoseRepositoryFolderCanBeWrittenIsNotReviewed(): void
    {
        $this->givenTheCheckoutIsReadOnly();
        new Process(['chmod', 'u+w', $this->repository->path.'/.git'])->run();

        $run = $this->whenTheReviewRuns();

        self::assertSame(2, $run->getExitCode());
        self::assertStringContainsString('/.git can be written', $this->outputFile('review.log'));
        self::assertFileDoesNotExist($this->repository->path.'/.git/.intakt-review-probe');
        self::assertTheCliDidNotRun();
    }

    /**
     * @param array<string, string|false> $environment
     */
    #[DataProvider('notTheProxyAlone')]
    public function testAJobWhoseNetworkIsNotTheProxyAloneIsNotReviewed(array $environment, string $reason): void
    {
        $this->givenTheCheckoutIsReadOnly();

        $run = $this->whenTheReviewRuns($environment);

        self::assertSame(3, $run->getExitCode());
        self::assertStringContainsString($reason, $this->outputFile('review.log'));
        self::assertTheCliDidNotRun();
    }

    /**
     * @return iterable<string, array{array<string, string|false>, string}>
     */
    public static function notTheProxyAlone(): iterable
    {
        yield 'no allowlist' => [['INTAKT_JOB_EGRESS' => false], 'the job has no egress allowlist'];
        yield 'an empty allowlist' => [['INTAKT_JOB_EGRESS' => ''], 'the job has no egress allowlist'];
        yield 'HTTPS_PROXY unset' => [['HTTPS_PROXY' => false], "HTTPS_PROXY is not the job's proxy"];
        yield 'https_proxy elsewhere' => [['https_proxy' => 'http://outside.example:3128'], "https_proxy is not the job's proxy"];
        yield 'HTTP_PROXY empty' => [['HTTP_PROXY' => ''], "HTTP_PROXY is not the job's proxy"];
        yield 'http_proxy on another port' => [['http_proxy' => 'http://egress-proxy:8080'], "http_proxy is not the job's proxy"];
    }

    public function testAJobInItsReducedModeIsNotReviewed(): void
    {
        $this->givenTheCheckoutIsReadOnly();

        $run = $this->whenTheReviewRuns(['INTAKT_REDUCED_MODE' => 'egress']);

        self::assertSame(4, $run->getExitCode());
        self::assertStringContainsString('the start check failed (egress), so the review does not run', $this->outputFile('review.log'));
        self::assertTheCliDidNotRun();
    }

    public function testAnotherCliIsNotRun(): void
    {
        $this->givenTheCheckoutIsReadOnly();
        $this->cli = 'sh';

        $run = $this->whenTheReviewRuns();

        self::assertSame(64, $run->getExitCode());
        self::assertStringContainsString('unknown reviewer CLI: sh', $this->outputFile('review.log'));
        self::assertTheCliDidNotRun();
    }

    public function testAFailingCliStillEndsTheScriptWithZeroAndItsStatusInTheLog(): void
    {
        $this->givenTheCheckoutIsReadOnly();
        file_put_contents($this->folder.'/bin/claude', "#!/bin/sh\necho 'vendor unreachable' >&2\nexit 7\n");

        $run = $this->whenTheReviewRuns();

        self::assertSame(0, $run->getExitCode());
        self::assertStringContainsString("vendor unreachable\nclaude exited with 7\n", $this->outputFile('review.log'));
    }

    // The job's mount is read-only; here the write permission is taken away, which a root user would ignore.
    private function givenTheCheckoutIsReadOnly(): void
    {
        if (0 === posix_geteuid()) {
            self::markTestSkipped('root can write a folder without write permission.');
        }

        new Process(['chmod', '-R', 'a-w', $this->repository->path])->mustRun();
    }

    /**
     * @param array<string, string|false> $environment
     */
    private function whenTheReviewRuns(array $environment = []): Process
    {
        $run = new Process(
            ['/bin/sh', '-c', (string) file_get_contents(self::SCRIPT), 'intakt-review', $this->checkout ?? $this->repository->path, $this->headArgument ?? $this->head, $this->folder.'/prompt.md', $this->cli, 'claude-opus-5-5'],
            null,
            [...$this->jobEnvironment(), ...$environment],
            timeout: 20,
        );
        $run->run();

        return $run;
    }

    /**
     * @return array<string, string|false>
     */
    private function jobEnvironment(): array
    {
        // As the container sets them, and without the host's git and proxy variables, which would point the script elsewhere.
        $environment = [
            'HOME' => $this->folder.'/work',
            'TMPDIR' => $this->folder.'/work',
            'INTAKT_JOB_OUTPUT' => $this->folder.'/output',
            'INTAKT_JOB_EGRESS' => 'api.anthropic.com',
            'INTAKT_REDUCED_MODE' => false,
            'HTTP_PROXY' => self::PROXY,
            'HTTPS_PROXY' => self::PROXY,
            'http_proxy' => self::PROXY,
            'https_proxy' => self::PROXY,
            'NO_PROXY' => false,
            'no_proxy' => false,
            'ALL_PROXY' => false,
            'all_proxy' => false,
            'PATH' => $this->folder.'/bin:'.getenv('PATH'),
            'RECORD' => $this->folder,
        ];
        foreach (array_keys([...getenv(), ...$_ENV]) as $name) {
            if (is_string($name) && str_starts_with($name, 'GIT_')) {
                $environment[$name] = false;
            }
        }

        return $environment;
    }

    private function assertTheCliDidNotRun(): void
    {
        self::assertFileDoesNotExist($this->folder.'/arguments');
        self::assertFileDoesNotExist($this->folder.'/output/answer');
    }

    private function recorded(string $name): string
    {
        return (string) file_get_contents($this->folder.'/'.$name);
    }

    private function outputFile(string $name): string
    {
        return (string) file_get_contents($this->folder.'/output/'.$name);
    }
}
