<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use Intakt\Core\Dashboard\Infrastructure\LedgerBuildSteps;
use Intakt\Core\Ledger\Contract\PayloadFields;
use Intakt\Core\Dashboard\UseCase\ShowTask\ShowTaskController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Psr\Log\LogLevel;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

#[CoversClass(ShowTaskController::class)]
#[CoversClass(LedgerBuildSteps::class)]
#[UsesClass(PayloadFields::class)]
final class ShowTaskControllerTest extends DashboardPageTestCase
{
    public function testATaskThatStoppedAtTheMirrorShowsTheMirrorStepFailedWithItsReason(): void
    {
        $this->ledger->passBuildStep('alpha', '42', 'task');
        $this->ledger->failBuildStep('42', 'mirror', 'git fetch exited with 128');

        $page = $this->browser->request('GET', '/tasks/alpha/42');

        self::assertResponseIsSuccessful();
        self::assertSame('Task 42 in alpha', $page->filter('h1')->text());
        self::assertSame('Stopped at mirror: git fetch exited with 128', $page->filter('#task-where')->text());
        self::assertSame(['Task Passed', 'Mirror Failed', 'Clone Not reached', 'Build job Not reached', 'Hand back check Not reached', 'Pre push check Not reached', 'Push Not reached'], $this->stepHeads($page));
        self::assertSame('git fetch exited with 128', $page->filter('#step-mirror .step-failure')->text());
    }

    public function testATaskThatStoppedAtTheCloneShowsTheCloneStepFailedWithItsReason(): void
    {
        $this->ledger->passBuildStep('alpha', '42', 'task');
        $this->ledger->passBuildStep('alpha', '42', 'mirror');
        $this->ledger->failBuildStep('42', 'clone', 'the folder exists already');

        $page = $this->browser->request('GET', '/tasks/alpha/42');

        self::assertSame('Stopped at clone: the folder exists already', $page->filter('#task-where')->text());
        self::assertSame(['Task Passed', 'Mirror Passed', 'Clone Failed', 'Build job Not reached', 'Hand back check Not reached', 'Pre push check Not reached', 'Push Not reached'], $this->stepHeads($page));
        self::assertSame('the folder exists already', $page->filter('#step-clone .step-failure')->text());
    }

    public function testATaskWhosePushStepPassedSaysEveryStepPassed(): void
    {
        $this->ledger->passBuildStep('alpha', '42', 'task');
        $this->ledger->passBuildStep('alpha', '42', 'mirror');
        $this->ledger->passBuildStep('alpha', '42', 'clone');
        $this->ledger->passBuildStep('alpha', '42', 'build_job');
        $this->ledger->passBuildStep('alpha', '42', 'hand_back_check');
        $this->ledger->passBuildStep('alpha', '42', 'pre_push_check');
        $this->ledger->passBuildStep('alpha', '42', 'push');

        $page = $this->browser->request('GET', '/tasks/alpha/42');

        self::assertSame('Every step passed.', $page->filter('#task-where')->text());
        self::assertSame(['Task Passed', 'Mirror Passed', 'Clone Passed', 'Build job Passed', 'Hand back check Passed', 'Pre push check Passed', 'Push Passed'], $this->stepHeads($page));
        self::assertCount(0, $page->filter('.step-failure'));
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
        self::assertSame(['Task Passed', 'Mirror Passed', 'Clone Passed', 'Build job Passed', 'Hand back check Passed', 'Pre push check Not reached', 'Push Not reached'], $this->stepHeads($page));
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
        self::assertSame(['Task Passed', 'Mirror Passed', 'Clone Passed', 'Build job Passed', 'Hand back check Passed', 'Pre push check Failed', 'Push Not reached'], $this->stepHeads($page));
    }

    public function testATaskThatStoppedAtThePushShowsThePushStepFailedWithItsReason(): void
    {
        $this->passStepsBeforeThePush();
        $this->ledger->failBuildStep('42', 'push', 'the remote refused the push of task-42 (git push exited with 1)');

        $page = $this->browser->request('GET', '/tasks/alpha/42');

        self::assertSame('Stopped at push: the remote refused the push of task-42 (git push exited with 1)', $page->filter('#task-where')->text());
        self::assertSame(['Task Passed', 'Mirror Passed', 'Clone Passed', 'Build job Passed', 'Hand back check Passed', 'Pre push check Passed', 'Push Failed'], $this->stepHeads($page));
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
        self::assertSame(['Task Passed', 'Mirror Passed', 'Clone Passed', 'Build job Passed', 'Hand back check Not reached', 'Pre push check Not reached', 'Push Not reached'], $this->stepHeads($page));
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
        self::assertSame(['Task Passed', 'Mirror Passed', 'Clone Passed', 'Build job Passed', 'Hand back check Failed', 'Pre push check Not reached', 'Push Not reached'], $this->stepHeads($page));
    }

    public function testATaskWhoseCloneStepPassedSaysTheCloneIsTheLastRecordedStep(): void
    {
        $this->ledger->passBuildStep('alpha', '42', 'task');
        $this->ledger->passBuildStep('alpha', '42', 'mirror');
        $this->ledger->passBuildStep('alpha', '42', 'clone');

        $page = $this->browser->request('GET', '/tasks/alpha/42');

        self::assertSame('Last recorded step: clone passed.', $page->filter('#task-where')->text());
        self::assertSame(['Task Passed', 'Mirror Passed', 'Clone Passed', 'Build job Not reached', 'Hand back check Not reached', 'Pre push check Not reached', 'Push Not reached'], $this->stepHeads($page));
    }

    public function testATaskThatStoppedAtTheBuildJobShowsItsVerdict(): void
    {
        $this->ledger->passBuildStep('alpha', '42', 'task');
        $this->ledger->passBuildStep('alpha', '42', 'mirror');
        $this->ledger->passBuildStep('alpha', '42', 'clone');
        $this->ledger->failBuildStep('42', 'build_job', 'the build made no commit on the task\'s branch');

        $page = $this->browser->request('GET', '/tasks/alpha/42');

        self::assertSame('Stopped at build_job: the build made no commit on the task\'s branch', $page->filter('#task-where')->text());
        self::assertSame(['Task Passed', 'Mirror Passed', 'Clone Passed', 'Build job Failed', 'Hand back check Not reached', 'Pre push check Not reached', 'Push Not reached'], $this->stepHeads($page));
        self::assertSame('the build made no commit on the task\'s branch', $page->filter('#step-build_job .step-failure')->text());
    }

    public function testATaskWhoseLatestStepPassedBeforeTheCloneSaysWhereItIs(): void
    {
        $this->ledger->passBuildStep('alpha', '42', 'task');

        $page = $this->browser->request('GET', '/tasks/alpha/42');

        self::assertSame('Last recorded step: task passed.', $page->filter('#task-where')->text());
        self::assertSame(['Task Passed', 'Mirror Not reached', 'Clone Not reached', 'Build job Not reached', 'Hand back check Not reached', 'Pre push check Not reached', 'Push Not reached'], $this->stepHeads($page));
    }

    public function testATaskBuiltAgainShowsOnlyItsLatestRun(): void
    {
        $this->ledger->passBuildStep('alpha', '42', 'task');
        $this->ledger->failBuildStep('42', 'mirror', 'git fetch exited with 128');
        $this->ledger->passBuildStep('alpha', '42', 'task');
        $this->ledger->passBuildStep('alpha', '42', 'mirror');
        $this->ledger->failBuildStep('42', 'clone', 'the folder exists already');

        $page = $this->browser->request('GET', '/tasks/alpha/42');

        self::assertSame(['Task Passed', 'Mirror Passed', 'Clone Failed', 'Build job Not reached', 'Hand back check Not reached', 'Pre push check Not reached', 'Push Not reached'], $this->stepHeads($page));
        self::assertStringNotContainsString('git fetch', $page->filter('main')->text());
    }

    public function testALaterRunThatFailedAtTheTaskStepShowsTheLaterStepsNotReached(): void
    {
        $this->ledger->passBuildStep('alpha', '42', 'task');
        $this->ledger->passBuildStep('alpha', '42', 'mirror');
        $this->ledger->passBuildStep('alpha', '42', 'clone');
        $this->ledger->failBuildStep('42', 'task', 'issue 42 is a pull request');

        $page = $this->browser->request('GET', '/tasks/alpha/42');

        self::assertSame(['Task Failed', 'Mirror Not reached', 'Clone Not reached', 'Build job Not reached', 'Hand back check Not reached', 'Pre push check Not reached', 'Push Not reached'], $this->stepHeads($page));
    }

    public function testTheStepsOfAnotherTaskOrProjectAreNotShown(): void
    {
        $this->ledger->passBuildStep('alpha', '42', 'task');
        $this->ledger->failBuildStep('43', 'task', 'issue 43 is a pull request');
        $this->ledger->recordBuildStep(['project' => 'beta', 'task' => '42', 'step' => 'mirror', 'outcome' => 'failed', 'failure' => 'beta mirror failed']);

        $page = $this->browser->request('GET', '/tasks/alpha/42');

        self::assertSame(['Task Passed', 'Mirror Not reached', 'Clone Not reached', 'Build job Not reached', 'Hand back check Not reached', 'Pre push check Not reached', 'Push Not reached'], $this->stepHeads($page));
        self::assertStringNotContainsString('failed', $page->filter('main')->text());
    }

    public function testAStepRowTheBuildDoesNotWriteIsLeftOut(): void
    {
        $this->ledger->passBuildStep('alpha', '42', 'task');
        $this->ledger->recordBuildStep(['project' => 'alpha', 'task' => '42', 'step' => 'mirror', 'outcome' => 'failed', 'failure' => null]);
        $this->ledger->recordBuildStep(['project' => 'alpha', 'task' => '42', 'step' => 'pull_request', 'outcome' => 'passed', 'failure' => null]);

        $page = $this->browser->request('GET', '/tasks/alpha/42');

        self::assertSame(['Task Passed', 'Mirror Not reached', 'Clone Not reached', 'Build job Not reached', 'Hand back check Not reached', 'Pre push check Not reached', 'Push Not reached'], $this->stepHeads($page));
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

    public function testATaskTheLedgerHoldsNoStepOfIsNotFound(): void
    {
        $this->ledger->passBuildStep('alpha', '42', 'task');

        $this->browser->request('GET', '/tasks/alpha/7');

        self::assertResponseStatusCodeSame(404);
        self::assertLoggedError(LogLevel::WARNING, sprintf('Uncaught PHP Exception %s: "The ledger holds no build step of this task."', NotFoundHttpException::class));
    }

    /**
     * @return list<string>
     */
    private function stepHeads(Crawler $page): array
    {
        return $page->filter('section.build-step .round-head')->each(static fn (Crawler $head): string => $head->text());
    }

    private function passStepsBeforeThePush(): void
    {
        foreach (['task', 'mirror', 'clone', 'build_job', 'hand_back_check', 'pre_push_check'] as $step) {
            $this->ledger->passBuildStep('alpha', '42', $step);
        }
    }
}
