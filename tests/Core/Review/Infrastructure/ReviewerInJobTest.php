<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Infrastructure;

use Closure;
use FilesystemIterator;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\ResolvedModel;
use Intakt\Core\Job\Contract\JobCause;
use Intakt\Core\Job\Contract\JobEnd;
use Intakt\Core\Job\Contract\JobOutput;
use Intakt\Core\Job\Contract\JobResult;
use Intakt\Core\Job\Contract\JobRunner;
use Intakt\Core\Job\Contract\JobSpec;
use Intakt\Core\Review\Contract\ChangeUnderReview;
use Intakt\Core\Review\Contract\Checkout;
use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\JobReview;
use Intakt\Core\Review\Contract\ReviewBrief;
use Intakt\Core\Review\Contract\ReviewerConversation;
use Intakt\Core\Review\Contract\ReviewJobRequest;
use Intakt\Core\Review\Contract\ReviewRequest;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Core\Review\Contract\VerdictParser;
use Intakt\Core\Review\Contract\VerdictUnreadable;
use Intakt\Core\Review\Infrastructure\HeadClones;
use Intakt\Core\Review\Infrastructure\JobPrompt;
use Intakt\Core\Review\Infrastructure\OpenFindingIds;
use Intakt\Core\Review\Infrastructure\ReviewerInJob;
use Intakt\Core\Review\Infrastructure\ReviewerPrompt;
use Intakt\Core\Review\Infrastructure\ReviewJob;
use Intakt\Core\Review\Infrastructure\ReviewJobAnswer;
use Intakt\Core\Review\Infrastructure\ReviewJobImages;
use Intakt\Core\Review\Infrastructure\VerdictInText;
use Intakt\Plugin\Forge\Contract\CommitId;
use Intakt\Tests\Support\StoredSerializerFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\AI\Platform\StructuredOutput\ResponseFormatFactory;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
use Symfony\Component\Uid\UuidV7;
use Symfony\Component\Validator\Validation;

// The job runner is scripted, because the build sandbox has no Docker daemon, but what it runs is the real job script on the clone the reviewer is given, made read-only as the job's mount makes it.
#[CoversClass(ReviewerInJob::class)]
#[CoversClass(HeadClones::class)]
#[CoversClass(JobPrompt::class)]
#[CoversClass(VerdictInText::class)]
#[CoversClass(OpenFindingIds::class)]
#[UsesClass(ReviewJob::class)]
#[UsesClass(ReviewJobAnswer::class)]
#[UsesClass(ReviewJobImages::class)]
#[UsesClass(ReviewJobRequest::class)]
#[UsesClass(ReviewerPrompt::class)]
#[UsesClass(Checkout::class)]
#[UsesClass(JobReview::class)]
#[UsesClass(CommitId::class)]
#[UsesClass(ResolvedModel::class)]
#[UsesClass(VerdictParser::class)]
#[UsesClass(VerdictUnreadable::class)]
final class ReviewerInJobTest extends TestCase
{
    private const string SCRIPT = __DIR__.'/../../../../docker/job/review.sh';

    // Because CI runs as a user without a git identity, and git refuses a commit without one.
    private const array IDENTITY = ['-c', 'user.name=Test', '-c', 'user.email=test@example.invalid', '-c', 'commit.gpgsign=false'];

    private const string APPROVE = '{"verdict":"approve","findings":[],"resolved":[],"follow_ups":[]}';

    private string $folder = '';

    private string $worktree = '';

    private string $head = '';

    private string $older = '';

    /** @var list<string> */
    private array $mounted = [];

    /** @var array<string, string> */
    private array $seen = [];

    protected function setUp(): void
    {
        if (0 === posix_geteuid()) {
            self::markTestSkipped('This user ignores file permissions (superuser), so no checkout is read-only.');
        }
        $this->folder = sys_get_temp_dir().'/intakt-reviewer-in-job-'.bin2hex(random_bytes(6));
        new Filesystem()->mkdir([$this->folder.'/source', $this->folder.'/bin', $this->folder.'/output', $this->folder.'/job']);
        $this->git($this->folder.'/source', 'init', '--quiet', '--initial-branch=main');
        $this->older = $this->commit('src/Cart.php', "<?php\n");
        $this->head = $this->commit('src/Cart.php', "<?php\n// changed\n");
        // A git worktree, because the round's checkout is one and the job's script refuses its .git file.
        $this->worktree = $this->folder.'/worktree';
        $this->git($this->folder.'/source', 'worktree', 'add', '--quiet', '--detach', $this->worktree, $this->head);
    }

    protected function tearDown(): void
    {
        if ('' === $this->folder) {
            return;
        }
        foreach ($this->mounted as $clone) {
            $this->changeModes($clone, static fn (int $mode): int => $mode | 0o200);
        }
        new Filesystem()->remove($this->folder);
    }

    public function testTheReviewersVerdictFromTheJobIsTheReviewsVerdict(): void
    {
        $verdict = $this->reviewInJob($this->answering("Looks right.\n\n```json\n".self::APPROVE."\n```"));

        self::assertSame(Decision::Approve, $verdict->verdict);
    }

    public function testTheJobsScriptAcceptsTheCloneAsAReadOnlyCheckoutOfTheExactHead(): void
    {
        $this->reviewInJob($this->answering("```json\n".self::APPROVE."\n```"));

        self::assertSame('0', $this->seen['exit'], $this->seen['log']);
        self::assertSame($this->head, $this->seen['head']);
    }

    public function testTheCloneHoldsTheHeadAloneAndNoEarlierCommit(): void
    {
        $this->reviewInJob($this->answering("```json\n".self::APPROVE."\n```"));

        self::assertSame($this->head, $this->seen['commits']);
        self::assertStringNotContainsString($this->older, $this->seen['commits']);
    }

    public function testTheCloneNamesNoPathOnTheHost(): void
    {
        $this->reviewInJob($this->answering("```json\n".self::APPROVE."\n```"));

        self::assertStringNotContainsString($this->folder, $this->seen['config']);
    }

    public function testTheJobIsAskedForTheHeadAndTheReviewersRole(): void
    {
        $this->reviewInJob($this->answering("```json\n".self::APPROVE."\n```"));

        self::assertSame("intakt-review\n".ReviewJob::CHECKOUT."\n".$this->head."\n".ReviewJob::PROMPT."\nclaude_code\nclaude-sonnet-5-5", $this->seen['command']);
    }

    public function testThePromptCarriesTheTaskAndAsksForTheVerdictAsOneJsonBlock(): void
    {
        $this->reviewInJob($this->answering("```json\n".self::APPROVE."\n```"));

        self::assertStringContainsString('Add a cart.', $this->seen['prompt']);
        self::assertStringContainsString('one fenced block that opens with a line ```json', $this->seen['prompt']);
    }

    public function testTheCloneIsRemovedAfterTheJob(): void
    {
        $this->reviewInJob($this->answering("```json\n".self::APPROVE."\n```"));

        self::assertDirectoryDoesNotExist($this->mounted[0]);
    }

    public function testTheCloneIsRemovedAlsoWhenTheAnswerIsUnreadable(): void
    {
        try {
            $this->reviewInJob($this->answering('No verdict here.'));
        } catch (VerdictUnreadable) {
        }

        self::assertDirectoryDoesNotExist($this->mounted[0]);
    }

    public function testAnAnswerWithTwoJsonBlocksIsUnreadable(): void
    {
        $this->expectException(VerdictUnreadable::class);

        $this->reviewInJob($this->answering("```json\n".self::APPROVE."\n```\n\n```json\n".self::APPROVE."\n```"));
    }

    public function testAVerdictThatFailsValidationIsUnreadable(): void
    {
        $this->expectException(VerdictUnreadable::class);

        $this->reviewInJob($this->answering("```json\n".'{"verdict":"changes","findings":[{"id":"","severity":"P1","file":null,"line":null,"symbol":null,"text":"","fixable_by":"builder","repeat":false,"requirement":null,"evidence":null,"regression":false}],"resolved":[],"follow_ups":[]}'."\n```"));
    }

    public function testAVerdictResolvingAnIdThatWasNotOpenIsUnreadable(): void
    {
        $this->expectException(VerdictUnreadable::class);

        $this->reviewInJob($this->answering("```json\n".'{"verdict":"approve","findings":[],"resolved":["F9"],"follow_ups":[]}'."\n```"));
    }

    public function testAHeadTheCheckoutDoesNotHoldFailsBeforeAnyJobRuns(): void
    {
        try {
            $this->reviewInJob($this->answering("```json\n".self::APPROVE."\n```"), str_repeat('f', 40));
            self::fail('A head the checkout does not hold was cloned.');
        } catch (ProcessFailedException) {
        }

        self::assertSame([], $this->mounted);
        self::assertSame([], array_values(array_diff((array) scandir($this->folder.'/clones'), ['.', '..'])));
    }

    // The real job script on the mounted clone, read-only, so the clone is held to the checks a job makes; claude is scripted because no test reaches a vendor.
    private function answering(string $text): JobRunner
    {
        $runner = self::createStub(JobRunner::class);
        $runner->method('run')->willReturnCallback(function (JobSpec $spec) use ($text): JobResult {
            $clone = $spec->inputs[0]->hostPath;
            $this->mounted[] = $clone;
            $this->seen['prompt'] = (string) file_get_contents($spec->inputs[1]->hostPath);
            $this->seen['command'] = implode("\n", array_slice($spec->command, 3));
            $this->seen['config'] = (string) file_get_contents($clone.'/.git/config');
            $this->seen['commits'] = trim($this->git($clone, 'rev-list', '--all'));
            $this->changeModes($clone, static fn (int $mode): int => $mode & ~0o222);
            file_put_contents($this->folder.'/answer.txt', json_encode(['type' => 'result', 'is_error' => false, 'result' => $text], JSON_THROW_ON_ERROR));
            $this->givenClaude('git rev-parse HEAD > "$RECORD/head"; cat "$RECORD/answer.txt"');
            $run = $this->runTheScript($clone, $spec->inputs[1]->hostPath);
            $this->changeModes($clone, static fn (int $mode): int => $mode | 0o200);
            $this->seen['exit'] = (string) $run->getExitCode();
            $this->seen['log'] = (string) @file_get_contents($this->folder.'/output/'.ReviewJob::LOG);
            $this->seen['head'] = trim((string) @file_get_contents($this->folder.'/head'));

            return new JobResult(new JobEnd(JobCause::Exited, $run->getExitCode() ?? 1), new JobOutput([ReviewJob::ANSWER => (string) @file_get_contents($this->folder.'/output/'.ReviewJob::ANSWER), ReviewJob::LOG => $this->seen['log']]));
        });

        return $runner;
    }

    private function reviewInJob(JobRunner $runner, ?string $head = null): Verdict
    {
        $reviewer = new ReviewerInJob(
            RemembersNothing::prompt(),
            new JobPrompt(new ResponseFormatFactory()),
            new HeadClones(new Filesystem(), $this->folder.'/clones'),
            new ReviewJob(new ReviewJobImages(['alpha' => 'intakt-job/alpha:review']), $runner, new Filesystem(), self::SCRIPT, $this->folder.'/prompts'),
            new VerdictParser(),
            Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator(),
            StoredSerializerFactory::create(),
        );
        $brief = new ReviewBrief(new ChangeUnderReview('alpha', 'Add a cart.', 'diff --git a/src/Cart.php b/src/Cart.php'), []);
        $request = new ReviewRequest(new Checkout($this->worktree), $brief, new ReviewerConversation('You review for correctness.', new UuidV7()));

        return $reviewer->review($request, new JobReview(new CommitId($head ?? $this->head), 'correctness'), new ResolvedModel(Provider::ClaudeCode, 'claude-sonnet-5-5'));
    }

    private function runTheScript(string $checkout, string $prompt): Process
    {
        $proxy = 'http://egress-proxy:3128';
        $environment = [
            'HOME' => $this->folder.'/job',
            'TMPDIR' => $this->folder.'/job',
            'INTAKT_JOB_OUTPUT' => $this->folder.'/output',
            'INTAKT_JOB_EGRESS' => 'api.anthropic.com',
            'INTAKT_REDUCED_MODE' => false,
            'HTTP_PROXY' => $proxy,
            'HTTPS_PROXY' => $proxy,
            'http_proxy' => $proxy,
            'https_proxy' => $proxy,
            'NO_PROXY' => false,
            'no_proxy' => false,
            'XDG_CONFIG_HOME' => false,
            'PATH' => $this->folder.'/bin:'.getenv('PATH'),
            'RECORD' => $this->folder,
        ];
        $prompts = new Filesystem();
        $prompts->copy($prompt, $this->folder.'/prompt.md');
        $run = new Process(['/bin/sh', '-c', (string) file_get_contents(self::SCRIPT), 'intakt-review', $checkout, $this->head, $this->folder.'/prompt.md', 'claude_code', 'claude-sonnet-5-5'], null, $environment, timeout: 20);
        $run->run();

        return $run;
    }

    private function givenClaude(string $body): void
    {
        file_put_contents($this->folder.'/bin/claude', "#!/bin/sh\n".$body."\n");
        chmod($this->folder.'/bin/claude', 0o755);
    }

    private function commit(string $file, string $content): string
    {
        $source = $this->folder.'/source';
        new Filesystem()->dumpFile($source.'/'.$file, $content);
        $this->git($source, 'add', '--all');
        $this->git($source, ...[...self::IDENTITY, 'commit', '--quiet', '-m', 'Change '.$file]);

        return trim($this->git($source, 'rev-parse', 'HEAD'));
    }

    /**
     * @param Closure(int): int $change
     */
    private function changeModes(string $root, Closure $change): void
    {
        if (!is_dir($root)) {
            return;
        }
        $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
        foreach ([$root, ...array_keys(iterator_to_array($entries))] as $path) {
            chmod($path, $change(fileperms($path) & 0o7777));
        }
    }

    private function git(string $directory, string ...$arguments): string
    {
        $git = new Process(['git', ...$arguments], $directory);
        $git->mustRun();

        return $git->getOutput();
    }
}
