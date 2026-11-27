<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use DateTimeImmutable;
use Intakt\Core\Build\Contract\ParkedQuestion;
use Intakt\Core\Build\Contract\ParkedQuestions;
use Intakt\Core\Dashboard\Infrastructure\LatestBuildRun;
use Intakt\Core\Dashboard\Infrastructure\LedgerBuildSteps;
use Intakt\Core\Dashboard\Infrastructure\ParkedQuestionTexts;
use Intakt\Core\Dashboard\UseCase\ShowTask\ShowTaskController;
use Intakt\Core\Ledger\Contract\PayloadFields;
use Intakt\Core\Platform\Contract\Redactor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use Psr\Log\LogLevel;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

#[CoversClass(ShowTaskController::class)]
#[CoversClass(LedgerBuildSteps::class)]
#[CoversClass(LatestBuildRun::class)]
#[CoversClass(ParkedQuestionTexts::class)]
#[UsesClass(PayloadFields::class)]
#[UsesClass(ParkedQuestion::class)]
final class ShowTaskControllerTest extends DashboardPageTestCase
{
    // The newer run's id sorts first, so only the order of the rows can tell which run started last.
    private const string OLDER_RUN = 'f1d0e2c4-5b6a-4c8d-9e0f-000000000002';

    private const string NEWER_RUN = 'f1d0e2c4-5b6a-4c8d-9e0f-000000000001';

    private const string NAMED_RUN = '3c9a7b52-1e4d-4f6a-8b2c-5d7e9f0a1b3c';

    public function testATaskThatStoppedAtTheMirrorShowsTheMirrorStepFailedWithItsReason(): void
    {
        $this->ledger->passBuildStep('alpha', '42', 'task');
        $this->ledger->failBuildStep('42', 'mirror', 'git fetch exited with 128');

        $page = $this->browser->request('GET', '/tasks/alpha/42');

        self::assertResponseIsSuccessful();
        self::assertSame('Task 42 in alpha', $page->filter('h1')->text());
        self::assertSame('Stopped at mirror: git fetch exited with 128', $page->filter('#task-where')->text());
        self::assertSame(['Task Passed', 'Mirror Failed', 'Clone Not reached', 'Build job Not reached', 'Hand back check Not reached', 'Pre push check Not reached', 'Push Not reached', 'Pull request Not reached'], $this->stepHeads($page));
        self::assertSame('git fetch exited with 128', $page->filter('#step-mirror .step-failure')->text());
    }

    public function testATaskThatStoppedAtTheCloneShowsTheCloneStepFailedWithItsReason(): void
    {
        $this->ledger->passBuildStep('alpha', '42', 'task');
        $this->ledger->passBuildStep('alpha', '42', 'mirror');
        $this->ledger->failBuildStep('42', 'clone', 'the folder exists already');

        $page = $this->browser->request('GET', '/tasks/alpha/42');

        self::assertSame('Stopped at clone: the folder exists already', $page->filter('#task-where')->text());
        self::assertSame(['Task Passed', 'Mirror Passed', 'Clone Failed', 'Build job Not reached', 'Hand back check Not reached', 'Pre push check Not reached', 'Push Not reached', 'Pull request Not reached'], $this->stepHeads($page));
        self::assertSame('the folder exists already', $page->filter('#step-clone .step-failure')->text());
    }

    public function testATaskWhosePullRequestStepPassedSaysEveryStepPassed(): void
    {
        $this->passStepsBeforeThePush();
        $this->ledger->passBuildStep('alpha', '42', 'push');
        $this->ledger->passBuildStep('alpha', '42', 'pull_request');

        $page = $this->browser->request('GET', '/tasks/alpha/42');

        self::assertSame('Every step passed.', $page->filter('#task-where')->text());
        self::assertSame(['Task Passed', 'Mirror Passed', 'Clone Passed', 'Build job Passed', 'Hand back check Passed', 'Pre push check Passed', 'Push Passed', 'Pull request Passed'], $this->stepHeads($page));
        self::assertCount(0, $page->filter('.step-failure'));
    }

    public function testATaskThatStoppedAtThePullRequestShowsThePullRequestStepFailedWithItsReason(): void
    {
        $this->passStepsBeforeThePush();
        $this->ledger->passBuildStep('alpha', '42', 'push');
        $this->ledger->failBuildStep('42', 'pull_request', 'the forge refused the pull request (HTTP 422)');

        $page = $this->browser->request('GET', '/tasks/alpha/42');

        self::assertSame('Stopped at pull_request: the forge refused the pull request (HTTP 422)', $page->filter('#task-where')->text());
        self::assertSame('Pull request Failed', $this->stepHeads($page)[7]);
    }

    public function testATaskWhoseHandBackCheckStepPassedSaysTheHandBackCheckIsTheLastRecordedStep(): void
    {
        $this->ledger->passBuildStep('alpha', '42', 'task');
        $this->ledger->passBuildStep('alpha', '42', 'mirror');
        $this->ledger->passBuildStep('alpha', '42', 'clone');
        $this->ledger->passBuildStep('alpha', '42', 'build_job');
        $this->ledger->passBuildStep('alpha', '42', 'hand_back_check');

        $page = $this->browser->request('GET', '/tasks/alpha/42');

        self::assertSame('Last recorded step: hand_back_check passed.', $page->filter('#task-where')->text());
        self::assertSame(['Task Passed', 'Mirror Passed', 'Clone Passed', 'Build job Passed', 'Hand back check Passed', 'Pre push check Not reached', 'Push Not reached', 'Pull request Not reached'], $this->stepHeads($page));
    }

    public function testATaskThatStoppedAtThePrePushCheckShowsItsReason(): void
    {
        $this->ledger->passBuildStep('alpha', '42', 'task');
        $this->ledger->passBuildStep('alpha', '42', 'mirror');
        $this->ledger->passBuildStep('alpha', '42', 'clone');
        $this->ledger->passBuildStep('alpha', '42', 'build_job');
        $this->ledger->passBuildStep('alpha', '42', 'hand_back_check');
        $this->ledger->failBuildStep('42', 'pre_push_check', 'the check command failed (exit code 2)');

        $page = $this->browser->request('GET', '/tasks/alpha/42');

        self::assertSame('Stopped at pre_push_check: the check command failed (exit code 2)', $page->filter('#task-where')->text());
        self::assertSame(['Task Passed', 'Mirror Passed', 'Clone Passed', 'Build job Passed', 'Hand back check Passed', 'Pre push check Failed', 'Push Not reached', 'Pull request Not reached'], $this->stepHeads($page));
    }

    public function testATaskThatStoppedAtThePushShowsThePushStepFailedWithItsReason(): void
    {
        $this->passStepsBeforeThePush();
        $this->ledger->failBuildStep('42', 'push', 'the remote refused the push of task-42 (git push exited with 1)');

        $page = $this->browser->request('GET', '/tasks/alpha/42');

        self::assertSame('Stopped at push: the remote refused the push of task-42 (git push exited with 1)', $page->filter('#task-where')->text());
        self::assertSame(['Task Passed', 'Mirror Passed', 'Clone Passed', 'Build job Passed', 'Hand back check Passed', 'Pre push check Passed', 'Push Failed', 'Pull request Not reached'], $this->stepHeads($page));
        self::assertSame('the remote refused the push of task-42 (git push exited with 1)', $page->filter('#step-push .step-failure')->text());
    }

    public function testATaskWhosePrePushCheckPassedSaysThePushIsNotReachedYet(): void
    {
        $this->passStepsBeforeThePush();

        $page = $this->browser->request('GET', '/tasks/alpha/42');

        self::assertSame('Last recorded step: pre_push_check passed.', $page->filter('#task-where')->text());
        self::assertSame('Push Not reached', $this->stepHeads($page)[6]);
    }

    public function testACredentialInAPushFailureIsRedacted(): void
    {
        $token = 'ghp_'.str_repeat('b2', 18);
        $this->passStepsBeforeThePush();
        $this->ledger->failBuildStep('42', 'push', 'the forge refused the write for token '.$token);

        $page = $this->browser->request('GET', '/tasks/alpha/42');

        self::assertSame('the forge refused the write for token [redacted]', $page->filter('#step-push .step-failure')->text());
        self::assertStringNotContainsString($token, (string) $this->browser->getResponse()->getContent());
    }

    public function testATaskWhoseBuildJobStepPassedSaysTheBuildJobIsTheLastRecordedStep(): void
    {
        $this->ledger->passBuildStep('alpha', '42', 'task');
        $this->ledger->passBuildStep('alpha', '42', 'mirror');
        $this->ledger->passBuildStep('alpha', '42', 'clone');
        $this->ledger->passBuildStep('alpha', '42', 'build_job');

        $page = $this->browser->request('GET', '/tasks/alpha/42');

        self::assertSame('Last recorded step: build_job passed.', $page->filter('#task-where')->text());
        self::assertSame(['Task Passed', 'Mirror Passed', 'Clone Passed', 'Build job Passed', 'Hand back check Not reached', 'Pre push check Not reached', 'Push Not reached', 'Pull request Not reached'], $this->stepHeads($page));
    }

    public function testATaskThatStoppedAtTheHandBackCheckShowsItsRefusal(): void
    {
        $this->ledger->passBuildStep('alpha', '42', 'task');
        $this->ledger->passBuildStep('alpha', '42', 'mirror');
        $this->ledger->passBuildStep('alpha', '42', 'clone');
        $this->ledger->passBuildStep('alpha', '42', 'build_job');
        $this->ledger->failBuildStep('42', 'hand_back_check', 'the bundle is not a readable git bundle');

        $page = $this->browser->request('GET', '/tasks/alpha/42');

        self::assertSame('Stopped at hand_back_check: the bundle is not a readable git bundle', $page->filter('#task-where')->text());
        self::assertSame(['Task Passed', 'Mirror Passed', 'Clone Passed', 'Build job Passed', 'Hand back check Failed', 'Pre push check Not reached', 'Push Not reached', 'Pull request Not reached'], $this->stepHeads($page));
    }

    public function testATaskWhoseCloneStepPassedSaysTheCloneIsTheLastRecordedStep(): void
    {
        $this->ledger->passBuildStep('alpha', '42', 'task');
        $this->ledger->passBuildStep('alpha', '42', 'mirror');
        $this->ledger->passBuildStep('alpha', '42', 'clone');

        $page = $this->browser->request('GET', '/tasks/alpha/42');

        self::assertSame('Last recorded step: clone passed.', $page->filter('#task-where')->text());
        self::assertSame(['Task Passed', 'Mirror Passed', 'Clone Passed', 'Build job Not reached', 'Hand back check Not reached', 'Pre push check Not reached', 'Push Not reached', 'Pull request Not reached'], $this->stepHeads($page));
    }

    public function testATaskThatStoppedAtTheBuildJobShowsItsVerdict(): void
    {
        $this->ledger->passBuildStep('alpha', '42', 'task');
        $this->ledger->passBuildStep('alpha', '42', 'mirror');
        $this->ledger->passBuildStep('alpha', '42', 'clone');
        $this->ledger->failBuildStep('42', 'build_job', 'the build made no commit on the task\'s branch');

        $page = $this->browser->request('GET', '/tasks/alpha/42');

        self::assertSame('Stopped at build_job: the build made no commit on the task\'s branch', $page->filter('#task-where')->text());
        self::assertSame(['Task Passed', 'Mirror Passed', 'Clone Passed', 'Build job Failed', 'Hand back check Not reached', 'Pre push check Not reached', 'Push Not reached', 'Pull request Not reached'], $this->stepHeads($page));
        self::assertSame('the build made no commit on the task\'s branch', $page->filter('#step-build_job .step-failure')->text());
    }

    public function testATaskWhoseLatestStepPassedBeforeTheCloneSaysWhereItIs(): void
    {
        $this->ledger->passBuildStep('alpha', '42', 'task');

        $page = $this->browser->request('GET', '/tasks/alpha/42');

        self::assertSame('Last recorded step: task passed.', $page->filter('#task-where')->text());
        self::assertSame(['Task Passed', 'Mirror Not reached', 'Clone Not reached', 'Build job Not reached', 'Hand back check Not reached', 'Pre push check Not reached', 'Push Not reached', 'Pull request Not reached'], $this->stepHeads($page));
    }

    public function testATaskBuiltAgainShowsOnlyItsLatestRun(): void
    {
        $this->ledger->passBuildStep('alpha', '42', 'task');
        $this->ledger->failBuildStep('42', 'mirror', 'git fetch exited with 128');
        $this->ledger->passBuildStep('alpha', '42', 'task');
        $this->ledger->passBuildStep('alpha', '42', 'mirror');
        $this->ledger->failBuildStep('42', 'clone', 'the folder exists already');

        $page = $this->browser->request('GET', '/tasks/alpha/42');

        self::assertSame(['Task Passed', 'Mirror Passed', 'Clone Failed', 'Build job Not reached', 'Hand back check Not reached', 'Pre push check Not reached', 'Push Not reached', 'Pull request Not reached'], $this->stepHeads($page));
        self::assertStringNotContainsString('git fetch', $page->filter('main')->text());
    }

    public function testALaterRunThatFailedAtTheTaskStepShowsTheLaterStepsNotReached(): void
    {
        $this->ledger->passBuildStep('alpha', '42', 'task');
        $this->ledger->passBuildStep('alpha', '42', 'mirror');
        $this->ledger->passBuildStep('alpha', '42', 'clone');
        $this->ledger->failBuildStep('42', 'task', 'issue 42 is a pull request');

        $page = $this->browser->request('GET', '/tasks/alpha/42');

        self::assertSame(['Task Failed', 'Mirror Not reached', 'Clone Not reached', 'Build job Not reached', 'Hand back check Not reached', 'Pre push check Not reached', 'Push Not reached', 'Pull request Not reached'], $this->stepHeads($page));
    }

    public function testTheStepsOfAnotherTaskOrProjectAreNotShown(): void
    {
        $this->ledger->passBuildStep('alpha', '42', 'task');
        $this->ledger->failBuildStep('43', 'task', 'issue 43 is a pull request');
        $this->ledger->recordBuildStep(['project' => 'beta', 'task' => '42', 'step' => 'mirror', 'outcome' => 'failed', 'failure' => 'beta mirror failed']);

        $page = $this->browser->request('GET', '/tasks/alpha/42');

        self::assertSame(['Task Passed', 'Mirror Not reached', 'Clone Not reached', 'Build job Not reached', 'Hand back check Not reached', 'Pre push check Not reached', 'Push Not reached', 'Pull request Not reached'], $this->stepHeads($page));
        self::assertStringNotContainsString('failed', $page->filter('main')->text());
    }

    public function testAnUnnamedStepThatRepeatsWithoutATaskRowStartsANewRunSoEachStepShowsOnce(): void
    {
        $this->ledger->passBuildStep('alpha', '42', 'task');
        $this->ledger->passBuildStep('alpha', '42', 'mirror');
        $this->ledger->passBuildStep('alpha', '42', 'clone');
        $this->ledger->failBuildStep('42', 'mirror', 'fetch refused');

        $page = $this->browser->request('GET', '/tasks/alpha/42');

        self::assertCount(1, $page->filter('#step-mirror'));
        self::assertSame(['Mirror Failed', 'Clone Not reached'], array_slice($this->stepHeads($page), 0, 2));
    }

    public function testAnOlderRunThatEndsAStepAfterTheNewerRunFailedItLeavesTheNewerRunsFailureShown(): void
    {
        $this->stepOfRun(self::OLDER_RUN, 'task');
        $this->stepOfRun(self::NEWER_RUN, 'task');
        $this->stepOfRun(self::NEWER_RUN, 'mirror', 'fetch refused');
        $this->stepOfRun(self::OLDER_RUN, 'mirror');
        $this->stepOfRun(self::OLDER_RUN, 'clone');

        $page = $this->browser->request('GET', '/tasks/alpha/42');

        self::assertSame(['Task Passed', 'Mirror Failed', 'Clone Not reached'], array_slice($this->stepHeads($page), 0, 3));
        self::assertStringContainsString('Stopped at mirror: fetch refused', $page->filter('#task-where')->text());
    }

    public function testTheRunStartedLastIsTheLatestRunThoughAnOlderRunWroteTheLastRow(): void
    {
        $this->stepOfRun(self::OLDER_RUN, 'task');
        $this->stepOfRun(self::NEWER_RUN, 'task');
        $this->stepOfRun(self::OLDER_RUN, 'mirror');

        $page = $this->browser->request('GET', '/tasks/alpha/42');

        self::assertSame(['Task Passed', 'Mirror Not reached'], array_slice($this->stepHeads($page), 0, 2));
    }

    public function testANamedRunAfterUnnamedRowsShowsOnlyItsOwnSteps(): void
    {
        $this->ledger->passBuildStep('alpha', '42', 'task');
        $this->ledger->failBuildStep('42', 'mirror', 'git fetch exited with 128');
        $this->stepOfRun(self::NAMED_RUN, 'task');

        $page = $this->browser->request('GET', '/tasks/alpha/42');

        self::assertSame(['Task Passed', 'Mirror Not reached'], array_slice($this->stepHeads($page), 0, 2));
    }

    public function testASecondRowOfAStepInTheSameNamedRunIsLeftOut(): void
    {
        $this->stepOfRun(self::NAMED_RUN, 'task');
        $this->stepOfRun(self::NAMED_RUN, 'mirror');
        $this->stepOfRun(self::NAMED_RUN, 'mirror', 'fetch refused');

        $page = $this->browser->request('GET', '/tasks/alpha/42');

        self::assertCount(1, $page->filter('#step-mirror'));
        self::assertSame('Mirror Passed', $page->filter('#step-mirror .round-head')->text());
    }

    public function testARowWhoseRunIsNoUuidIsLeftOut(): void
    {
        $this->stepOfRun(self::NAMED_RUN, 'task');
        $this->ledger->recordBuildStep(['project' => 'alpha', 'task' => '42', 'run' => 7, 'step' => 'mirror', 'outcome' => 'failed', 'failure' => 'fetch refused']);
        $this->ledger->recordBuildStep(['project' => 'alpha', 'task' => '42', 'run' => '', 'step' => 'mirror', 'outcome' => 'failed', 'failure' => 'fetch refused']);
        $this->ledger->recordBuildStep(['project' => 'alpha', 'task' => '42', 'run' => 'named', 'step' => 'mirror', 'outcome' => 'failed', 'failure' => 'fetch refused']);

        $page = $this->browser->request('GET', '/tasks/alpha/42');

        self::assertSame(['Task Passed', 'Mirror Not reached'], array_slice($this->stepHeads($page), 0, 2));
    }

    public function testARunWhoseTaskRowWasDroppedAsMalformedDoesNotMixInThePreviousRunsSteps(): void
    {
        $this->ledger->passBuildStep('alpha', '42', 'task');
        $this->ledger->passBuildStep('alpha', '42', 'mirror');
        $this->ledger->passBuildStep('alpha', '42', 'clone');
        $this->ledger->recordBuildStep(['project' => 'alpha', 'task' => '42', 'step' => 'pull_request', 'outcome' => 'passed', 'failure' => null]);
        $this->ledger->failBuildStep('42', 'mirror', 'fetch refused');

        $page = $this->browser->request('GET', '/tasks/alpha/42');

        self::assertCount(1, $page->filter('#step-mirror'));
        self::assertSame('Clone Not reached', $page->filter('#step-clone .round-head')->text());
        self::assertSame('Mirror Failed', $page->filter('#step-mirror .round-head')->text());
    }

    public function testAStepRowTheBuildDoesNotWriteIsLeftOut(): void
    {
        $this->ledger->passBuildStep('alpha', '42', 'task');
        $this->ledger->recordBuildStep(['project' => 'alpha', 'task' => '42', 'step' => 'mirror', 'outcome' => 'failed', 'failure' => null]);
        $this->ledger->recordBuildStep(['project' => 'alpha', 'task' => '42', 'step' => 'merge', 'outcome' => 'passed', 'failure' => null]);

        $page = $this->browser->request('GET', '/tasks/alpha/42');

        self::assertSame(['Task Passed', 'Mirror Not reached', 'Clone Not reached', 'Build job Not reached', 'Hand back check Not reached', 'Pre push check Not reached', 'Push Not reached', 'Pull request Not reached'], $this->stepHeads($page));
    }

    public function testACredentialInTheFailureIsRedacted(): void
    {
        $token = 'ghp_'.str_repeat('a1', 18);
        $this->ledger->passBuildStep('alpha', '42', 'task');
        $this->ledger->failBuildStep('42', 'mirror', 'fetch refused for token '.$token);

        $page = $this->browser->request('GET', '/tasks/alpha/42');

        self::assertSame('fetch refused for token [redacted]', $page->filter('#step-mirror .step-failure')->text());
        self::assertStringNotContainsString($token, (string) $this->browser->getResponse()->getContent());
    }

    public function testMarkupInTheFailureIsShownAsText(): void
    {
        $this->ledger->failBuildStep('42', 'task', '<b>exit 1</b>');

        $page = $this->browser->request('GET', '/tasks/alpha/42');

        self::assertSame('<b>exit 1</b>', $page->filter('#step-task .step-failure')->text());
        self::assertCount(0, $page->filter('main b'));
    }

    public function testATaskParkedAtTheBuildJobShowsItsQuestionChoicesAndRecommendation(): void
    {
        $this->parkWith(new ParkedQuestion('Keep the old flag?', ['keep it', 'drop it'], 'drop it', new DateTimeImmutable('2026-11-20 10:00:00'), self::NAMED_RUN));

        $page = $this->browser->request('GET', '/tasks/alpha/42');

        self::assertSame('Parked at build_job: the build asked a question.', $page->filter('#task-where')->text());
        self::assertSame(['Task Passed', 'Mirror Passed', 'Clone Passed', 'Build job Asked', 'Hand back check Not reached', 'Pre push check Not reached', 'Push Not reached'], $this->stepHeads($page));
        self::assertSame('Keep the old flag?', $page->filter('#parked-question-text')->text());
        self::assertSame(['keep it', 'drop it'], $page->filter('#parked-question-choices li')->each(static fn (Crawler $choice): string => $choice->text()));
        self::assertSame('Recommended: drop it', $page->filter('#parked-question-recommendation')->text());
        self::assertSame('Parked 2026-11-20 10:00:00 UTC', $page->filter('#parked-question .muted')->text());
        self::assertCount(0, $page->filter('.step-failure'));
    }

    public function testACredentialAndMarkupInTheParkedQuestionAreRedactedAndShownAsText(): void
    {
        $token = 'ghp_'.str_repeat('c3', 18);
        $this->parkWith(new ParkedQuestion('<b>Use</b> '.$token.'?', ['<i>yes</i>', 'no'], 'no', new DateTimeImmutable('2026-11-20 10:00:00'), self::NAMED_RUN));

        $page = $this->browser->request('GET', '/tasks/alpha/42');

        self::assertSame('<b>Use</b> [redacted]?', $page->filter('#parked-question-text')->text());
        self::assertSame('<i>yes</i>', $page->filter('#parked-question-choices li')->first()->text());
        self::assertCount(0, $page->filter('main b, main i'));
        self::assertStringNotContainsString($token, (string) $this->browser->getResponse()->getContent());
    }

    public function testAnAskedRunWhoseQuestionIsNoLongerStoredSaysItIsParkedWithoutAQuestion(): void
    {
        $this->parkWith(null);

        $page = $this->browser->request('GET', '/tasks/alpha/42');

        self::assertSame('Parked at build_job: the build asked a question.', $page->filter('#task-where')->text());
        self::assertCount(0, $page->filter('#parked-question'));
    }

    public function testALaterRunAfterAParkedOneShowsNoQuestionAndNeverReadsOne(): void
    {
        $questions = $this->createMock(ParkedQuestions::class);
        $questions->expects($this->never())->method('of');
        self::getContainer()->set(ParkedQuestions::class, $questions);
        $this->passStepsBeforeTheBuildJob();
        $this->ledger->recordBuildStep(['project' => 'alpha', 'task' => '42', 'step' => 'build_job', 'outcome' => 'asked', 'failure' => null]);
        $this->passStepsBeforeTheBuildJob();
        $this->ledger->passBuildStep('alpha', '42', 'build_job');

        $page = $this->browser->request('GET', '/tasks/alpha/42');

        self::assertSame('Last recorded step: build_job passed.', $page->filter('#task-where')->text());
        self::assertCount(0, $page->filter('#parked-question'));
    }

    public function testAnAskedRowOfAStepOtherThanTheBuildJobIsLeftOut(): void
    {
        $this->ledger->passBuildStep('alpha', '42', 'task');
        $this->ledger->recordBuildStep(['project' => 'alpha', 'task' => '42', 'step' => 'mirror', 'outcome' => 'asked', 'failure' => null]);
        $this->ledger->recordBuildStep(['project' => 'alpha', 'task' => '42', 'step' => 'build_job', 'outcome' => 'asked', 'failure' => 'a question']);

        $page = $this->browser->request('GET', '/tasks/alpha/42');

        self::assertSame(['Task Passed', 'Mirror Not reached', 'Clone Not reached', 'Build job Not reached', 'Hand back check Not reached', 'Pre push check Not reached', 'Push Not reached'], $this->stepHeads($page));
    }

    public function testATaskTheLedgerHoldsNoStepOfIsNotFound(): void
    {
        $this->ledger->passBuildStep('alpha', '42', 'task');

        $this->browser->request('GET', '/tasks/alpha/7');

        self::assertResponseStatusCodeSame(404);
        self::assertLoggedError(LogLevel::WARNING, sprintf('Uncaught PHP Exception %s: "The ledger holds no build step of this task."', NotFoundHttpException::class));
    }

    public function testAQuestionAnotherRunParkedIsNotShownOnTheTimelineOfTheRunThePageShows(): void
    {
        $this->parkWith(new ParkedQuestion('Keep the old flag?', ['keep it', 'drop it'], 'drop it', new DateTimeImmutable('2026-11-20 10:00:00'), self::OLDER_RUN));

        $page = $this->browser->request('GET', '/tasks/alpha/42');

        self::assertSame('Parked at build_job: the build asked a question.', $page->filter('#task-where')->text());
        self::assertCount(0, $page->filter('#parked-question'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function tasksNotNamedByTheirPlainNumber(): iterable
    {
        yield 'a leading space' => [' 42'];
        yield 'a trailing space' => ['42 '];
        yield 'a plus sign' => ['+42'];
        yield 'a leading zero' => ['042'];
        yield 'a minus sign' => ['-42'];
        yield 'zero' => ['0'];
        yield 'a number past the integer range' => ['99999999999999999999'];
        yield 'no number' => ['sample'];
    }

    #[DataProvider('tasksNotNamedByTheirPlainNumber')]
    public function testATaskNotNamedByItsPlainNumberNeverReachesTheStoredQuestions(string $task): void
    {
        $questions = $this->createMock(ParkedQuestions::class);
        $questions->expects($this->never())->method('of');

        self::assertNull(new ParkedQuestionTexts($questions, self::createStub(Redactor::class))->of('alpha', $task, self::NAMED_RUN));
    }

    /**
     * @return list<string>
     */
    private function stepHeads(Crawler $page): array
    {
        return $page->filter('section.build-step .round-head')->each(static fn (Crawler $head): string => $head->text());
    }

    private function stepOfRun(string $run, string $step, ?string $failure = null): void
    {
        $this->ledger->recordBuildStep(['project' => 'alpha', 'task' => '42', 'run' => $run, 'step' => $step, 'outcome' => null === $failure ? 'passed' : 'failed', 'failure' => $failure]);
    }

    private function parkWith(?ParkedQuestion $question): void
    {
        $questions = self::createStub(ParkedQuestions::class);
        $questions->method('of')->willReturnCallback(static fn (string $project, int $task): ?ParkedQuestion => 'alpha' === $project && 42 === $task ? $question : null);
        self::getContainer()->set(ParkedQuestions::class, $questions);
        foreach (['task', 'mirror', 'clone'] as $step) {
            $this->stepOfRun(self::NAMED_RUN, $step);
        }

        $this->ledger->recordBuildStep(['project' => 'alpha', 'task' => '42', 'run' => self::NAMED_RUN, 'step' => 'build_job', 'outcome' => 'asked', 'failure' => null]);
    }

    private function passStepsBeforeTheBuildJob(): void
    {
        foreach (['task', 'mirror', 'clone'] as $step) {
            $this->ledger->passBuildStep('alpha', '42', $step);
        }
    }

    private function passStepsBeforeThePush(): void
    {
        foreach (['task', 'mirror', 'clone', 'build_job', 'hand_back_check', 'pre_push_check'] as $step) {
            $this->ledger->passBuildStep('alpha', '42', $step);
        }
    }
}
