<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Infrastructure;

use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Contract\AccountLogin;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\ResolvedModel;
use Intakt\Core\Job\Contract\JobCause;
use Intakt\Core\Job\Contract\JobEgress;
use Intakt\Core\Job\Contract\JobEnd;
use Intakt\Core\Job\Contract\JobId;
use Intakt\Core\Job\Contract\JobInput;
use Intakt\Core\Job\Contract\JobLimits;
use Intakt\Core\Job\Contract\JobOutput;
use Intakt\Core\Job\Contract\JobOutputFile;
use Intakt\Core\Job\Contract\JobOutputRefused;
use Intakt\Core\Job\Contract\JobResult;
use Intakt\Core\Job\Contract\JobRunner;
use Intakt\Core\Job\Contract\JobSpec;
use Intakt\Core\Review\Contract\ReviewJobRequest;
use Intakt\Core\Review\Infrastructure\ClaudeCodeJobEvents;
use Intakt\Core\Review\Infrastructure\ReportedUsage;
use Intakt\Core\Review\Infrastructure\ReviewJob;
use Intakt\Core\Review\Infrastructure\ReviewJobAnswer;
use Intakt\Core\Review\Infrastructure\ReviewJobFailure;
use Intakt\Core\Review\Infrastructure\ReviewJobImages;
use Intakt\Tests\Core\Job\OutputFiles;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;

#[CoversClass(ReviewJob::class)]
#[CoversClass(ReviewJobFailure::class)]
#[UsesClass(ReviewJobRequest::class)]
#[UsesClass(ReviewJobAnswer::class)]
#[UsesClass(ClaudeCodeJobEvents::class)]
#[UsesClass(ReportedUsage::class)]
#[UsesClass(ReviewJobImages::class)]
#[UsesClass(ResolvedModel::class)]
#[UsesClass(JobSpec::class)]
#[UsesClass(JobId::class)]
#[UsesClass(JobInput::class)]
#[UsesClass(JobLimits::class)]
#[UsesClass(JobEgress::class)]
#[UsesClass(JobResult::class)]
#[UsesClass(JobEnd::class)]
#[UsesClass(JobOutput::class)]
final class ReviewJobTest extends TestCase
{
    private const string SCRIPT = __DIR__.'/../../../../docker/job/review.sh';

    private const string HEAD = '0123456789abcdef0123456789abcdef01234567';

    private const string CLAUDE_ANSWER = '{"type":"result","subtype":"success","is_error":false,"result":"{\"verdict\":\"approve\"}"}';

    private string $folder = '';

    private ?JobSpec $ran = null;

    private string $promptSeen = '';

    public function testAJobThatExitedWithTheCliAnswerGivesTheAnswersText(): void
    {
        $answer = $this->reviews($this->answered(self::CLAUDE_ANSWER))->run($this->request());

        self::assertSame('{"verdict":"approve"}', $answer->text);
    }

    public function testTheCheckoutAndThePromptAreTheJobsOnlyInputs(): void
    {
        (void) $this->reviews($this->answered(self::CLAUDE_ANSWER))->run($this->request());

        self::assertNotNull($this->ran);
        self::assertSame([$this->folder.'/checkout', ReviewJob::CHECKOUT], [$this->ran->inputs[0]->hostPath, $this->ran->inputs[0]->containerPath]);
        self::assertSame(ReviewJob::PROMPT, $this->ran->inputs[1]->containerPath);
        self::assertCount(2, $this->ran->inputs);
    }

    public function testTheHeadTheProviderAndTheModelReachTheReviewScript(): void
    {
        (void) $this->reviews($this->answered(self::CLAUDE_ANSWER))->run($this->request());

        self::assertNotNull($this->ran);
        self::assertSame(
            ['/bin/sh', '-c', (string) file_get_contents(self::SCRIPT), 'intakt-review', ReviewJob::CHECKOUT, self::HEAD, ReviewJob::PROMPT, 'claude_code', 'claude-sonnet-5-5'],
            [...$this->ran->command],
        );
    }

    public function testTheJobRunsInTheProjectsReviewImage(): void
    {
        (void) $this->reviews($this->answered(self::CLAUDE_ANSWER))->run($this->request());

        self::assertNotNull($this->ran);
        self::assertSame('intakt-job/demo:review', $this->ran->image);
    }

    public function testThePromptFileHoldsThePromptWhileTheJobRunsAndIsRemovedAfterwards(): void
    {
        (void) $this->reviews($this->answered(self::CLAUDE_ANSWER))->run($this->request());

        self::assertNotNull($this->ran);
        self::assertSame('PLANT-prompt', $this->promptSeen);
        self::assertFileDoesNotExist($this->ran->inputs[1]->hostPath);
    }

    public function testThePromptFileIsRemovedWhenTheJobFails(): void
    {
        $runner = self::createStub(JobRunner::class);
        $prompt = null;
        $runner->method('run')->willReturnCallback(static function (JobSpec $spec) use (&$prompt): never {
            $prompt = $spec->inputs[1]->hostPath;

            throw new RuntimeException('engine gone');
        });

        try {
            (void) $this->useCase($runner)->run($this->request());
        } catch (RuntimeException) {
        }

        self::assertIsString($prompt);
        self::assertFileDoesNotExist($prompt);
    }

    public function testAClaudeCodeReviewerMayReachAnthropicsApiOnly(): void
    {
        (void) $this->reviews($this->answered(self::CLAUDE_ANSWER))->run($this->request());

        self::assertNotNull($this->ran);
        self::assertSame(['api.anthropic.com'], [...$this->ran->egress->hosts]);
    }

    public function testACodexReviewerMayReachTheChatGptBackendOnly(): void
    {
        $answer = "{\"type\":\"item.completed\",\"item\":{\"type\":\"agent_message\",\"text\":\"done\"}}\n{\"type\":\"turn.completed\",\"usage\":{}}\n";

        (void) $this->reviews($this->answered($answer, 'codex'))->run($this->request(new ResolvedModel(Provider::Codex, 'gpt-5.3-codex')));

        self::assertNotNull($this->ran);
        self::assertSame(['chatgpt.com'], [...$this->ran->egress->hosts]);
    }

    public function testAReviewerOnAnApiProviderIsRefusedBeforeAnyJobStarts(): void
    {
        $runner = self::createStub(JobRunner::class);
        $runner->method('run')->willThrowException(new RuntimeException('no job may start'));

        $this->expectException(ReviewJobFailure::class);
        $this->expectExceptionMessageIsOrContains('No review job runs a reviewer on the provider anthropic');

        (void) $this->useCase($runner)->run($this->request(new ResolvedModel(Provider::Anthropic, 'claude-sonnet-5-5')));
    }

    public function testAProjectWithoutAReviewImageIsRefusedAndLeavesNoPromptFile(): void
    {
        try {
            (void) $this->reviews($this->answered(self::CLAUDE_ANSWER))->run($this->request(project: 'other'));
            self::fail('A project without a review image ran a job.');
        } catch (ReviewJobFailure $failure) {
            self::assertStringContainsString('The project "other" has no review job image', $failure->getMessage());
        }

        self::assertNull($this->ran);
        self::assertSame([], glob($this->folder.'/prompts/*'));
    }

    public function testACheckoutThatIsNoFolderIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $request = new ReviewJobRequest('demo', $this->folder.'/missing', self::HEAD, 'correctness', new ResolvedModel(Provider::ClaudeCode, 'claude-sonnet-5-5'), 'PLANT-prompt');

        (void) $this->reviews($this->answered(self::CLAUDE_ANSWER))->run($request);
    }

    public function testTheJobIdNamesTheProjectTheHeadAndTheReviewerSoASecondJobOfThemIsRefusedWhileOneRuns(): void
    {
        $ids = [];
        foreach ([$this->request(), $this->request(), $this->request(reviewer: 'security'), $this->request(project: 'demo2')] as $request) {
            (void) $this->reviews($this->answered(self::CLAUDE_ANSWER), ['demo' => 'image', 'demo2' => 'image'])->run($request);
            self::assertNotNull($this->ran);
            $ids[] = $this->ran->id->value;
        }

        self::assertSame($ids[0], $ids[1]);
        self::assertCount(3, array_unique($ids));
        self::assertMatchesRegularExpression('/^review-[0-9a-f]{40}$/', $ids[0]);
    }

    public function testTheJobIdKeepsTheBoundaryBetweenProjectAndReviewer(): void
    {
        $ids = [];
        foreach ([['ab', 'c'], ['a', 'bc']] as [$project, $reviewer]) {
            (void) $this->reviews($this->answered(self::CLAUDE_ANSWER), [$project => 'image'])->run($this->request(
                project: $project,
                reviewer: $reviewer,
            ));
            self::assertNotNull($this->ran);
            $ids[] = $this->ran->id->value;
        }

        self::assertNotSame($ids[0], $ids[1]);
    }

    public function testARefusedCheckoutFailsWithTheScriptsReason(): void
    {
        $output = OutputFiles::of([ReviewJob::LOG => "fatal: some git error\nrefused: the checkout is writable\n"]);

        $this->expectException(ReviewJobFailure::class);
        $this->expectExceptionMessageIsOrContains('The review job refused to run the reviewer: the checkout is writable.');

        (void) $this->reviews(new JobResult(new JobEnd(JobCause::Exited, 1), $output))->run($this->request());
    }

    public function testAJobThatFailedWithoutAReasonNamesItsExitCode(): void
    {
        $this->expectException(ReviewJobFailure::class);
        $this->expectExceptionMessageIsOrContains('The review job refused to run the reviewer: it exited with 2.');

        (void) $this->reviews(new JobResult(new JobEnd(JobCause::Exited, 2), OutputFiles::of([])))->run($this->request());
    }

    public function testARefusedJobFailsWhateverItsAnswerSays(): void
    {
        $output = OutputFiles::of([ReviewJob::LOG => "refused: the checkout differs from the head\n", ReviewJob::ANSWER => self::CLAUDE_ANSWER]);

        $this->expectException(ReviewJobFailure::class);

        (void) $this->reviews(new JobResult(new JobEnd(JobCause::Exited, 1), $output))->run($this->request());
    }

    public function testAJobTheEngineStoppedForMemoryFailsWhateverItsAnswerSays(): void
    {
        $this->expectException(ReviewJobFailure::class);
        $this->expectExceptionMessageIsOrContains('The review job ran out of memory.');

        (void) $this->reviews(new JobResult(new JobEnd(JobCause::MemoryKilled, 137), $this->answered(self::CLAUDE_ANSWER)->output))->run($this->request());
    }

    public function testAJobTheEngineStoppedForTimeFailsWhateverItsAnswerSays(): void
    {
        $this->expectException(ReviewJobFailure::class);
        $this->expectExceptionMessageIsOrContains('The review job ran out of time.');

        (void) $this->reviews(new JobResult(new JobEnd(JobCause::TimedOut, 137), $this->answered(self::CLAUDE_ANSWER)->output))->run($this->request());
    }

    public function testAnOutputTheRunnerRefusesIsNoResult(): void
    {
        $runner = self::createStub(JobRunner::class);
        $runner->method('run')->willThrowException(JobOutputRefused::tooLarge('answer', 16));

        $this->expectException(ReviewJobFailure::class);
        $this->expectExceptionMessageIsOrContains('The review job left an output the runner refuses to read.');

        (void) $this->useCase($runner)->run($this->request());
    }

    public function testACliThatExitedWithAFailingStatusFailsWhateverItsAnswerSays(): void
    {
        $output = OutputFiles::of([ReviewJob::LOG => "claude_code exited with 0\nclaude_code exited with 1\n", ReviewJob::ANSWER => self::CLAUDE_ANSWER]);

        $this->expectException(ReviewJobFailure::class);
        $this->expectExceptionMessageIsOrContains('The reviewer\'s CLI claude_code exited with 1.');

        (void) $this->reviews(new JobResult(new JobEnd(JobCause::Exited, 0), $output))->run($this->request());
    }

    public function testACliLineTheCliForgedBeforeTheScriptsOwnDoesNotCount(): void
    {
        $output = OutputFiles::of([ReviewJob::LOG => "claude_code exited with 1\nclaude_code exited with 0\n", ReviewJob::ANSWER => self::CLAUDE_ANSWER]);

        $answer = $this->reviews(new JobResult(new JobEnd(JobCause::Exited, 0), $output))->run($this->request());

        self::assertSame('{"verdict":"approve"}', $answer->text);
    }

    public function testALogWithoutTheScriptsExitLineIsNoResult(): void
    {
        $output = OutputFiles::of([ReviewJob::LOG => '', ReviewJob::ANSWER => self::CLAUDE_ANSWER]);

        $this->expectException(ReviewJobFailure::class);
        $this->expectExceptionMessageIsOrContains('The review job did not report how the reviewer\'s CLI claude_code exited.');

        (void) $this->reviews(new JobResult(new JobEnd(JobCause::Exited, 0), $output))->run($this->request());
    }

    public function testAJobWithoutAnAnswerFileFails(): void
    {
        $output = OutputFiles::of([ReviewJob::LOG => "claude_code exited with 0\n"]);

        $this->expectException(ReviewJobFailure::class);
        $this->expectExceptionMessageIsOrContains('The reviewer\'s CLI claude_code gave no answer the review job can read.');

        (void) $this->reviews(new JobResult(new JobEnd(JobCause::Exited, 0), $output))->run($this->request());
    }

    public function testTheLoginIsAReadOnlyInputOfItsOwnAndOnNoCommandLine(): void
    {
        (void) $this->reviewsAnswering('fine')->run($this->requestWithLogin());

        self::assertSame(ReviewJob::LOGIN, $this->ran?->inputs[2]->containerPath);
        self::assertStringNotContainsString('PLANT-login-token', implode(' ', [...$this->ran->command]));
        self::assertFileDoesNotExist($this->ran->inputs[2]->hostPath);
    }

    public function testAnAnswerQuotingTheLoginComesOutRedacted(): void
    {
        $answer = $this->reviewsAnswering('it is PLANT-login-token and \u0050LANT-login-token')->run($this->requestWithLogin());

        self::assertSame('it is [redacted] and [redacted]', $answer->text);
    }

    public function testARefusalQuotingTheLoginComesOutRedacted(): void
    {
        $this->expectExceptionMessage('refused to run the reviewer: no [redacted] here.');

        (void) $this->reviews(new JobResult(new JobEnd(JobCause::Exited, 1), new JobOutput(new JobOutputFile(ReviewJob::LOG, "refused: no PLANT-login-token here\n"))))->run($this->requestWithLogin());
    }

    protected function setUp(): void
    {
        $this->folder = sys_get_temp_dir().'/intakt-review-job-'.bin2hex(random_bytes(6));
        new Filesystem()->mkdir($this->folder.'/checkout');
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->folder);
    }

    private function answered(string $answer, string $provider = 'claude_code'): JobResult
    {
        return new JobResult(new JobEnd(JobCause::Exited, 0), OutputFiles::of([ReviewJob::ANSWER => $answer, ReviewJob::LOG => $provider." exited with 0\n"]));
    }

    /**
     * @param array<string, string> $images
     */
    private function reviews(JobResult $result, array $images = ['demo' => 'intakt-job/demo:review']): ReviewJob
    {
        $runner = self::createStub(JobRunner::class);
        $runner->method('run')->willReturnCallback(function (JobSpec $spec) use ($result): JobResult {
            $this->ran = $spec;
            $this->promptSeen = (string) file_get_contents($spec->inputs[1]->hostPath);

            return $result;
        });

        return $this->useCase($runner, $images);
    }

    private function request(?ResolvedModel $model = null, string $project = 'demo', string $reviewer = 'correctness'): ReviewJobRequest
    {
        return new ReviewJobRequest(
            $project,
            $this->folder.'/checkout',
            self::HEAD,
            $reviewer,
            $model ?? new ResolvedModel(Provider::ClaudeCode, 'claude-sonnet-5-5'),
            'PLANT-prompt',
        );
    }

    private function reviewsAnswering(string $json): ReviewJob
    {
        return $this->reviews(new JobResult(new JobEnd(JobCause::Exited, 0), new JobOutput(new JobOutputFile(ReviewJob::ANSWER, '{"type":"result","is_error":false,"result":"'.$json.'"}'), new JobOutputFile(ReviewJob::LOG, "claude_code exited with 0\n"))));
    }

    private function requestWithLogin(): ReviewJobRequest
    {
        return new ReviewJobRequest('demo', $this->folder.'/checkout', self::HEAD, 'correctness', new ResolvedModel(Provider::ClaudeCode, 'claude-sonnet-5-5'), 'PLANT-prompt', new AccountLogin(new Account(Provider::ClaudeCode, Account::DEFAULT), 'PLANT-login-token'));
    }

    /**
     * @param array<string, string> $images
     */
    private function useCase(JobRunner $runner, array $images = ['demo' => 'intakt-job/demo:review']): ReviewJob
    {
        return new ReviewJob(new ReviewJobImages($images), $runner, new Filesystem(), self::SCRIPT, $this->folder.'/prompts');
    }
}
