<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubForge;

use Intakt\Core\Watcher\Domain\AdmissionDecision;
use Intakt\Core\Watcher\Domain\HeadAdmission;
use Intakt\Plugin\Forge\Contract\ChangeId;
use Intakt\Plugin\Forge\Contract\ChangeIdEmpty;
use Intakt\Plugin\Forge\Contract\ChangeNotFound;
use Intakt\Plugin\Forge\Contract\CiRun;
use Intakt\Plugin\Forge\Contract\CiRunOrigin;
use Intakt\Plugin\Forge\Contract\CiRunState;
use Intakt\Plugin\Forge\Contract\CiState;
use Intakt\Plugin\Forge\Contract\CommitId;
use Intakt\Plugin\Forge\Contract\ForgeCredentialsRefused;
use Intakt\Plugin\Forge\Contract\ForgeRateLimited;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\Forge\Contract\OpenChange;
use Intakt\Plugin\Forge\Contract\OpenChangeListing;
use Intakt\Plugin\GitHubForge\Contract\InstallationToken;
use Intakt\Plugin\GitHubForge\Domain\ExpectedInstallation;
use Intakt\Plugin\GitHubForge\Domain\Installation;
use Intakt\Plugin\GitHubForge\Domain\RateLimitReading;
use Intakt\Plugin\GitHubForge\Infrastructure\AppJwt;
use Intakt\Plugin\GitHubForge\Infrastructure\BudgetReserve;
use Intakt\Plugin\GitHubForge\Infrastructure\CheckedInstallationTokens;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubCalls;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubCiRuns;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubMoments;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubWatchReads;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationReadBack;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationReader;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationTokenMinter;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationTokens;
use Intakt\Plugin\GitHubForge\Infrastructure\PullRequestAddress;
use Intakt\Plugin\GitHubForge\Infrastructure\RateLimitReadings;
use Intakt\Plugin\GitHubForge\Infrastructure\RepositoryAddress;
use Intakt\Plugin\GitHubForge\Infrastructure\WatchedRepositories;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

#[CoversClass(GitHubWatchReads::class)]
#[CoversClass(GitHubCiRuns::class)]
#[CoversClass(RepositoryAddress::class)]
#[CoversClass(GitHubMoments::class)]
#[CoversClass(WatchedRepositories::class)]
#[UsesClass(AdmissionDecision::class)]
#[UsesClass(PullRequestAddress::class)]
#[UsesClass(ChangeId::class)]
#[UsesClass(ChangeIdEmpty::class)]
#[UsesClass(ChangeNotFound::class)]
#[UsesClass(CiRun::class)]
#[UsesClass(CiRunOrigin::class)]
#[UsesClass(CiState::class)]
#[UsesClass(CommitId::class)]
#[UsesClass(ForgeCredentialsRefused::class)]
#[UsesClass(ForgeReadFailed::class)]
#[UsesClass(OpenChange::class)]
#[UsesClass(OpenChangeListing::class)]
#[UsesClass(RateLimitReadings::class)]
#[UsesClass(RateLimitReading::class)]
#[UsesClass(BudgetReserve::class)]
#[UsesClass(ForgeRateLimited::class)]
#[UsesClass(InstallationToken::class)]
#[UsesClass(InstallationTokenMinter::class)]
#[UsesClass(InstallationTokens::class)]
#[UsesClass(AppJwt::class)]
#[UsesClass(CheckedInstallationTokens::class)]
#[UsesClass(InstallationReadBack::class)]
#[UsesClass(InstallationReader::class)]
#[UsesClass(GitHubCalls::class)]
#[UsesClass(ExpectedInstallation::class)]
#[UsesClass(Installation::class)]
final class GitHubWatchReadsTest extends TestCase
{
    private const string HEAD = '1111111111111111111111111111111111111111';
    private const string API = 'https://api.github.com/repos/acme/widgets';
    private const string VALIDATOR = 'W/"listing-1"';
    private const string PULLS = 'GET '.self::API.'/pulls';

    /** @var list<string> */
    private array $requested = [];
    /** @var list<array<mixed>> */
    private array $options = [];
    private string $listing = 'pulls_open.json';
    private int $listingStatus = 200;
    private string $listingEtag = 'W/"listing-2"';
    private string $runs = 'runs_green.json';
    private string $suites = 'suites_none.json';
    private int $failWith = 0;
    private ?int $remaining = null;
    private MockClock $clock;
    // Close after the recorded runs' creation, because a queued suite past the ceiling is left out.
    private string $ciNow = '2026-11-02 09:10:00 UTC';

    public function testBelowTheReserveASecondListingInOneTickMakesNoRequestAndWaitsForTheReset(): void
    {
        $this->remaining = 999;
        $reads = $this->reads();
        $reads->ciStateOf('alpha', new CommitId(self::HEAD));
        $reads->listOpen('alpha', null);

        try {
            $reads->listOpen('alpha', null);
            self::fail('The second listing was expected to wait for the reset.');
        } catch (ForgeRateLimited $limited) {
            self::assertEquals($this->clock->now()->modify('+30 minutes'), $limited->resetsAt);
        }

        self::assertCount(1, $this->listingRequests());
    }

    public function testBelowTheReserveTheNextTickListsAgain(): void
    {
        $this->remaining = 999;
        $reads = $this->reads();
        $reads->ciStateOf('alpha', new CommitId(self::HEAD));
        $reads->listOpen('alpha', null);
        $this->clock->modify('+1 minute');

        $reads->listOpen('alpha', null);

        self::assertCount(2, $this->listingRequests());
    }

    public function testAtTheReserveListingsAreNotHeldBack(): void
    {
        $this->remaining = 1000;
        $reads = $this->reads();
        $reads->ciStateOf('alpha', new CommitId(self::HEAD));

        $reads->listOpen('alpha', null);
        $reads->listOpen('alpha', null);

        self::assertCount(2, $this->listingRequests());
    }

    public function testBelowTheReserveTheCiOfAWaitingHeadIsReadOnEveryCall(): void
    {
        $this->remaining = 1;
        $reads = $this->reads();
        $reads->listOpen('alpha', null);
        $this->requested = [];

        $reads->ciStateOf('alpha', new CommitId(self::HEAD));
        $reads->ciStateOf('alpha', new CommitId(self::HEAD));

        self::assertCount(4, $this->requested);
    }

    public function testAListingAnsweredNotModifiedIsReportedUnchanged(): void
    {
        $this->listingStatus = 304;

        $listing = $this->reads()->listOpen('alpha', self::VALIDATOR);

        self::assertTrue($listing->isUnchanged());
    }

    public function testAnUnchangedListingCostsNoFurtherRead(): void
    {
        $this->listingStatus = 304;

        $this->reads()->listOpen('alpha', self::VALIDATOR);

        self::assertCount(1, $this->listingRequests());
    }

    public function testAListingSendsTheEarlierValidatorAndReturnsTheNewOne(): void
    {
        $listing = $this->reads()->listOpen('alpha', self::VALIDATOR);

        self::assertSame(self::VALIDATOR, $this->header($this->options[0], 'if-none-match'));
        self::assertSame('W/"listing-2"', $listing->validator);
    }

    public function testAFirstListingSendsNoValidator(): void
    {
        $this->reads()->listOpen('alpha', null);

        self::assertSame('', $this->header($this->options[0], 'if-none-match'));
    }

    public function testOpenPullRequestsAreListedWithTheirAddressHeadAndUpdateTimeInUtc(): void
    {
        $listing = $this->reads()->listOpen('alpha', null);

        self::assertSame(
            ['https://github.com/acme/widgets/pull/7|'.self::HEAD.'|2026-11-02T09:00:00+00:00', 'https://github.com/acme/widgets/pull/8|'.str_repeat('2', 40).'|2026-11-02T07:30:00+00:00'],
            array_map(static fn (OpenChange $change): string => $change->id->value.'|'.$change->head->hash.'|'.$change->updatedAt->format('c'), $listing->changes()),
        );
    }

    public function testAListingOverTwoPagesReturnsEveryPullRequestAndNoValidator(): void
    {
        $this->listing = 'pulls_page_full.json';

        $listing = $this->reads()->listOpen('alpha', null);

        self::assertCount(101, $listing->changes());
        self::assertNull($listing->validator);
    }

    public function testAPullRequestRepeatedOnPageOneAndPageTwoIsListedOnce(): void
    {
        $this->listing = 'pulls_page_shifted.json';

        $ids = array_map(static fn (OpenChange $change): string => $change->id->value, $this->reads()->listOpen('alpha', null)->changes());

        self::assertCount(101, $ids);
        self::assertSame(array_values(array_unique($ids)), $ids);
        self::assertSame('https://github.com/acme/widgets/pull/101', array_last($ids));
    }

    public function testAFullFirstPageFollowedByAnEmptyOneReturnsNoValidator(): void
    {
        $this->listing = 'pulls_page_full_only.json';

        $listing = $this->reads()->listOpen('alpha', null);

        self::assertCount(100, $listing->changes());
        self::assertNull($listing->validator);
    }

    public function testAnEmptyListIsAnEmptyListingWithItsValidator(): void
    {
        $this->listing = 'pulls_none.json';

        $listing = $this->reads()->listOpen('alpha', null);

        self::assertSame([], $listing->changes());
        self::assertSame('W/"listing-2"', $listing->validator);
    }

    public function testAnEmptyObjectInsteadOfAListingRaisesAReadFailure(): void
    {
        $this->listing = 'pulls_empty_object.json';

        $this->expectException(ForgeReadFailed::class);

        $this->reads()->listOpen('alpha', null);
    }

    public function testAnObjectOfPullRequestsInsteadOfAListingRaisesAReadFailure(): void
    {
        $this->listing = 'pulls_keyed_object.json';

        $this->expectException(ForgeReadFailed::class);

        $this->reads()->listOpen('alpha', null);
    }

    public function testAListingOfMoreThanTenPagesFailsTheRead(): void
    {
        $this->listing = 'pulls_endless.json';

        $this->expectException(ForgeReadFailed::class);

        $this->reads()->listOpen('alpha', null);
    }

    public function testAHeadWithAWorkflowRunInProgressIsUnfinished(): void
    {
        $this->runs = 'runs_in_progress.json';

        self::assertSame([CiRunState::Unfinished], $this->statesOfHead());
    }

    public function testAHeadWithAQueuedCheckSuiteIsUnfinished(): void
    {
        $this->runs = 'runs_none.json';
        $this->suites = 'suites_queued.json';

        self::assertSame([CiRunState::Unfinished], $this->statesOfHead());
    }

    public function testAQueuedWorkflowRunNextToANewerCompletedOneOnTheSameHeadIsQueued(): void
    {
        $this->runs = 'runs_queued_and_newer_green.json';

        self::assertSame([CiRunState::Succeeded, CiRunState::Queued], $this->statesOfHead());
    }

    public function testAGreenRunOfAnotherWorkflowWithTheSameNameIsNotANewerRunOfAQueuedOne(): void
    {
        $this->runs = 'runs_queued_and_green_of_another_workflow_of_the_same_name.json';

        [$queued, $green] = $this->state()->runs;

        self::assertFalse($green->isNewerRunOf($queued));
    }

    public function testAQueuedRerunIsUnfinishedBecauseItKeepsTheCreationTimeOfItsFirstAttempt(): void
    {
        $this->runs = 'runs_green_and_queued_rerun.json';

        self::assertSame([CiRunState::Succeeded, CiRunState::Unfinished], $this->statesOfHead());
    }

    public function testOfTwoRunsCreatedInTheSameSecondTheHigherRunNumberIsTheNewerRun(): void
    {
        $this->runs = 'runs_green_and_queued_in_the_same_second.json';

        [$green, $queued] = $this->state()->runs;

        self::assertTrue($green->isNewerRunOf($queued));
    }

    public function testACancelledAndAGreenPullRequestRunFromTheSameSecondWithoutTheirPullRequestLeaveTheHeadPending(): void
    {
        $this->runs = 'runs_cancelled_and_green_in_the_same_second_without_their_pull_request.json';

        self::assertSame(HeadAdmission::Pending, new AdmissionDecision()->admissionOf($this->state()));
    }

    public function testAQueuedPullRequestRunWhosePullRequestGitHubLeftOutIsUnfinished(): void
    {
        $this->runs = 'runs_green_and_queued_without_their_pull_request.json';

        self::assertSame([CiRunState::Succeeded, CiRunState::Unfinished], $this->statesOfHead());
    }

    public function testAQueuedPushRunWithoutAPullRequestIsQueued(): void
    {
        $this->runs = 'runs_queued_push_and_newer_green.json';

        self::assertSame([CiRunState::Succeeded, CiRunState::Queued], $this->statesOfHead());
    }

    public function testAFailedWorkflowRunIsFailed(): void
    {
        $this->runs = 'runs_failed.json';

        self::assertSame([CiRunState::Failed], $this->statesOfHead());
    }

    public function testAFailedCheckSuiteIsFailed(): void
    {
        $this->suites = 'suites_failed.json';

        self::assertSame([CiRunState::Succeeded, CiRunState::Succeeded, CiRunState::Failed], $this->statesOfHead());
    }

    public function testACancelledRunIsCancelledAndNotFailed(): void
    {
        $this->runs = 'runs_cancelled.json';

        self::assertSame([CiRunState::Cancelled], $this->statesOfHead());
    }

    public function testFinishedGreenRunsAndSuitesAreSucceeded(): void
    {
        $this->suites = 'suites_green.json';

        self::assertSame([CiRunState::Succeeded, CiRunState::Succeeded, CiRunState::Succeeded], $this->statesOfHead());
    }

    public function testRunsOfAnotherHeadAreLeftOut(): void
    {
        $this->runs = 'runs_other_head.json';

        self::assertSame([CiRunState::Succeeded], $this->statesOfHead());
    }

    public function testAQueuedCheckSuiteBeforeItsFirstCheckRunKeepsAGreenHeadUnfinished(): void
    {
        $this->suites = 'suites_empty_queue.json';

        self::assertSame([CiRunState::Succeeded, CiRunState::Succeeded, CiRunState::Unfinished], $this->statesOfHead());
    }

    public function testAQueuedCheckSuiteOfNoWorkflowRunWithoutCheckRunsIsLeftOutOnceItIsOlderThanTheCeiling(): void
    {
        $this->suites = 'suites_empty_queue.json';
        $this->ciNow = '2026-11-02 09:31:01 UTC';

        self::assertSame([CiRunState::Succeeded, CiRunState::Succeeded], $this->statesOfHead());
        self::assertSame(HeadAdmission::Green, new AdmissionDecision()->admissionOf($this->state()));
    }

    public function testAQueuedCheckSuiteOfNoWorkflowRunWithoutCheckRunsStaysUnfinishedAtTheCeiling(): void
    {
        $this->suites = 'suites_empty_queue.json';
        $this->ciNow = '2026-11-02 09:31:00 UTC';

        self::assertSame([CiRunState::Succeeded, CiRunState::Succeeded, CiRunState::Unfinished], $this->statesOfHead());
    }

    public function testAQueuedCheckSuiteOfNoWorkflowRunPastTheCeilingWithAnInvalidPullRequestNumberRaisesAReadFailure(): void
    {
        $this->suites = 'suites_empty_queue_invalid_number.json';
        $this->ciNow = '2026-11-02 09:31:01 UTC';

        $this->expectException(ForgeReadFailed::class);

        $this->state();
    }

    public function testAQueuedCheckSuiteOfNoWorkflowRunThatHasCheckRunsIsKeptPastTheCeiling(): void
    {
        $this->runs = 'runs_none.json';
        $this->suites = 'suites_queued.json';
        $this->ciNow = '2026-11-25 10:00:00 UTC';

        self::assertSame([CiRunState::Unfinished], $this->statesOfHead());
    }

    public function testAQueuedLinkedCheckSuiteWithoutCheckRunsIsKeptPastTheCeilingWhileAnUnlinkedOneIsLeftOut(): void
    {
        $this->runs = 'runs_linked_queued_and_newer_green.json';
        $this->suites = 'suites_empty_queue_and_linked_queued.json';
        $this->ciNow = '2026-11-25 10:00:00 UTC';

        self::assertSame(['10', '10', 'suite-of-workflow:10'], array_map(static fn (CiRun $run): string => $run->origin->name, $this->state()->runs));
    }

    public function testARunKeepsItsOriginAndStartTime(): void
    {
        $this->runs = 'runs_in_progress.json';

        $run = $this->state()->runs[0];

        self::assertSame('10|pull_request|https://github.com/acme/widgets/pull/7|2026-11-02T09:00:00+00:00|1', $run->origin->name.'|'.$run->origin->event.'|'.$run->origin->change?->value.'|'.$run->startedAt->format('c').'|'.$run->number);
    }

    public function testACheckSuiteOfNoWorkflowRunIsNamedAfterItsOwnIdAndItsEventIsCheckSuite(): void
    {
        $this->runs = 'runs_none.json';
        $this->suites = 'suites_green.json';

        $origin = $this->state()->runs[0]->origin;

        self::assertSame('suite:401|check_suite|https://github.com/acme/widgets/pull/7', $origin->name.'|'.$origin->event.'|'.$origin->change?->value);
    }

    public function testTwoCheckSuitesOfNoWorkflowRunOfOneAppNeverReplaceEachOther(): void
    {
        $this->runs = 'runs_none.json';
        $this->suites = 'suites_linked_queued_and_newer_green.json';

        [$queued, $green] = $this->state()->runs;

        self::assertSame(CiRunState::Unfinished, $queued->state);
        self::assertFalse($green->isNewerRunOf($queued));
    }

    public function testALinkedCheckSuiteTakesTheWorkflowEventAndPullRequestOfItsRunAndKeepsItsOwnStateAndCreationTime(): void
    {
        $this->runs = 'runs_linked_queued_and_newer_green.json';
        $this->suites = 'suites_linked_queued_and_newer_green.json';

        $suite = $this->state()->runs[2];

        self::assertSame('suite-of-workflow:10|pull_request|https://github.com/acme/widgets/pull/7|queued|2026-11-11T20:30:00+00:00|1', $suite->origin->name.'|'.$suite->origin->event.'|'.$suite->origin->change?->value.'|'.$suite->state->value.'|'.$suite->startedAt->format('c').'|'.$suite->number);
    }

    public function testAQueuedLinkedCheckSuiteWithoutCheckRunsIsReplacedByTheNewerSuiteOfARunForTheSamePullRequest(): void
    {
        $this->runs = 'runs_linked_queued_and_newer_green.json';
        $this->suites = 'suites_linked_queued_and_newer_green.json';

        [, , $queued, $green] = $this->state()->runs;

        self::assertTrue($green->isNewerRunOf($queued));
    }

    public function testAQueuedLinkedCheckSuiteIsNotReplacedByTheNewerSuiteOfARunForAnotherPullRequestWhenBothSuitesListNoPullRequest(): void
    {
        $this->runs = 'runs_linked_queued_and_newer_green_of_another_pull_request.json';
        $this->suites = 'suites_linked_queued_and_newer_green.json';

        [, , $queued, $green] = $this->state()->runs;

        self::assertFalse($green->isNewerRunOf($queued));
    }

    public function testAQueuedLinkedCheckSuiteOfAPullRequestRunIsNotReplacedByTheNewerSuiteOfAPushRunOfTheSameWorkflow(): void
    {
        $this->runs = 'runs_linked_queued_pull_request_and_newer_green_push.json';
        $this->suites = 'suites_linked_queued_and_newer_green.json';

        [, , $queued, $green] = $this->state()->runs;

        self::assertFalse($green->isNewerRunOf($queued));
    }

    public function testAQueuedLinkedCheckSuiteThatHasCheckRunsIsUnfinished(): void
    {
        $this->runs = 'runs_linked_queued_and_newer_green.json';
        $this->suites = 'suites_linked_queued_with_check_runs_and_newer_green.json';

        self::assertSame(CiRunState::Unfinished, $this->state()->runs[2]->state);
    }

    public function testAQueuedLinkedCheckSuiteWithoutACountIsUnfinished(): void
    {
        $this->runs = 'runs_linked_queued_and_newer_green.json';
        $this->suites = 'suites_linked_queued_without_a_count_and_newer_green.json';

        self::assertSame(CiRunState::Unfinished, $this->state()->runs[2]->state);
    }

    public function testAQueuedCheckSuiteOfAPullRequestRunWhosePullRequestGitHubLeftOutIsUnfinished(): void
    {
        $this->runs = 'runs_linked_queued_and_newer_green_without_their_pull_request.json';
        $this->suites = 'suites_linked_queued_and_newer_green.json';

        self::assertSame(CiRunState::Unfinished, $this->state()->runs[2]->state);
    }

    public function testAHeadWhoseQueuedRunAndQueuedSuiteANewerGreenRunAndSuiteReplacedIsGreen(): void
    {
        $this->runs = 'runs_linked_queued_and_newer_green.json';
        $this->suites = 'suites_linked_queued_and_newer_green.json';

        self::assertSame(HeadAdmission::Green, new AdmissionDecision()->admissionOf($this->state()));
    }

    public function testAGreenRunCreatedAfterItsOwnQueuedSuiteDoesNotReplaceThatSuite(): void
    {
        $this->runs = 'runs_green_created_after_its_queued_suite.json';
        $this->suites = 'suites_linked_queued_and_newer_green.json';

        self::assertSame(HeadAdmission::Pending, new AdmissionDecision()->admissionOf($this->state()));
    }

    public function testAHeadWhoseQueuedRunAndSuiteANewerRunAndSuiteOfTheSameSecondReplacedIsGreen(): void
    {
        $this->runs = 'runs_linked_queued_and_newer_green_in_one_second.json';
        $this->suites = 'suites_linked_queued_and_newer_green_in_one_second.json';

        self::assertSame(HeadAdmission::Green, new AdmissionDecision()->admissionOf($this->state()));
    }

    public function testTwoWorkflowRunsLinkedToOneCheckSuiteRaiseAReadFailure(): void
    {
        $this->runs = 'runs_linked_twice_to_one_suite.json';

        $this->expectExceptionObject(new ForgeReadFailed('GitHub linked two workflow runs to one check suite.'));

        $this->state();
    }

    public function testAWorkflowRunWithACheckSuiteIdThatIsNotAPositiveNumberRaisesAReadFailure(): void
    {
        $this->runs = 'runs_with_a_check_suite_id_that_is_not_a_number.json';

        $this->expectExceptionObject(new ForgeReadFailed('GitHub answered without a positive "check_suite_id".'));

        $this->state();
    }

    public function testACheckSuiteWithoutItsIdRaisesAReadFailure(): void
    {
        $this->suites = 'suites_without_id.json';

        $this->expectExceptionObject(new ForgeReadFailed('GitHub answered without a positive "id".'));

        $this->state();
    }

    public function testTheRunsAreAskedForTheExactHead(): void
    {
        $this->state();

        self::assertContains('GET '.self::API.'/actions/runs?head_sha='.self::HEAD.'&per_page=100', $this->requested);
        self::assertContains('GET '.self::API.'/commits/'.self::HEAD.'/check-suites?per_page=100', $this->requested);
    }

    public function testAHeadWithNoRunsHasAnEmptyState(): void
    {
        $this->runs = 'runs_none.json';

        self::assertTrue($this->state()->isEmpty());
    }

    public function testARefusedListingRaisesAReadFailureCausedByRefusedCredentials(): void
    {
        $this->failWith = 401;

        try {
            $this->reads()->listOpen('alpha', null);
            self::fail('The refused read was not reported.');
        } catch (ForgeReadFailed $failed) {
            self::assertInstanceOf(ForgeCredentialsRefused::class, $failed->getPrevious());
        }
    }

    public function testARefusedCiReadRaisesAReadFailureCausedByRefusedCredentials(): void
    {
        $this->failWith = 401;

        try {
            $this->reads()->ciStateOf('alpha', new CommitId(self::HEAD));
            self::fail('The refused read was not reported.');
        } catch (ForgeReadFailed $failed) {
            self::assertInstanceOf(ForgeCredentialsRefused::class, $failed->getPrevious());
        }
    }

    public function testAFailedListingRaisesAReadFailure(): void
    {
        $this->failWith = 500;

        $this->expectException(ForgeReadFailed::class);

        $this->reads()->listOpen('alpha', null);
    }

    public function testAMissingRepositoryRaisesAReadFailureOnTheListing(): void
    {
        $this->failWith = 404;

        $this->expectException(ForgeReadFailed::class);

        $this->reads()->listOpen('alpha', null);
    }

    public function testAFailedCiReadRaisesAReadFailure(): void
    {
        $this->failWith = 500;

        $this->expectException(ForgeReadFailed::class);

        $this->reads()->ciStateOf('alpha', new CommitId(self::HEAD));
    }

    public function testAnUnreachableGitHubRaisesAReadFailureThatDoesNotChainTheClientFailure(): void
    {
        $this->failWith = -1;

        try {
            $this->reads()->listOpen('alpha', null);
            self::fail('The failed read was not reported.');
        } catch (ForgeReadFailed $failed) {
            self::assertNull($failed->getPrevious());
        }
    }

    public function testAnAnswerThatIsNotJsonRaisesAReadFailure(): void
    {
        $this->listing = 'contents_widget.txt';

        $this->expectException(ForgeReadFailed::class);

        $this->reads()->listOpen('alpha', null);
    }

    public function testACompletedRunWithoutAConclusionRaisesAReadFailure(): void
    {
        $this->runs = 'runs_no_conclusion.json';

        $this->expectException(ForgeReadFailed::class);

        $this->state();
    }

    public function testAWorkflowRunWithoutItsWorkflowIdRaisesAReadFailure(): void
    {
        $this->runs = 'runs_without_workflow_id.json';

        $this->expectExceptionObject(new ForgeReadFailed('GitHub answered without a positive "workflow_id".'));

        $this->state();
    }

    public function testAQueuedWorkflowRunWithoutItsRunNumberRaisesAReadFailure(): void
    {
        $this->runs = 'runs_queued_without_run_number.json';

        $this->expectExceptionObject(new ForgeReadFailed('GitHub answered without a positive "run_number".'));

        $this->state();
    }

    public function testAQueuedWorkflowRunWithoutItsRunAttemptRaisesAReadFailure(): void
    {
        $this->runs = 'runs_queued_without_run_attempt.json';

        $this->expectExceptionObject(new ForgeReadFailed('GitHub answered without a positive "run_attempt".'));

        $this->state();
    }

    public function testAnAnswerWithoutTheListOfRunsRaisesAReadFailure(): void
    {
        $this->runs = 'access_tokens_404.json';

        $this->expectException(ForgeReadFailed::class);

        $this->state();
    }

    public function testMoreRunsThanOnePageHoldsRaiseAReadFailure(): void
    {
        $this->runs = 'runs_more_than_a_page.json';

        $this->expectException(ForgeReadFailed::class);

        $this->state();
    }

    public function testAListedPullRequestAtAnotherHostRaisesAReadFailure(): void
    {
        $this->listing = 'pulls_foreign_address.json';

        $this->expectException(ForgeReadFailed::class);

        $this->reads()->listOpen('alpha', null);
    }

    public function testAListedPullRequestWhoseAddressIsOnlyWhitespaceRaisesAReadFailure(): void
    {
        $this->listing = 'pulls_blank_address.json';

        try {
            $this->reads()->listOpen('alpha', null);
            self::fail('A blank address is no change this forge can read.');
        } catch (ForgeReadFailed $failure) {
            self::assertInstanceOf(ChangeIdEmpty::class, $failure->getPrevious());
        }
    }

    public function testAListedPullRequestWithAMomentItCannotReadRaisesAReadFailure(): void
    {
        $this->listing = 'pulls_bad_moment.json';

        $this->expectException(ForgeReadFailed::class);

        $this->reads()->listOpen('alpha', null);
    }

    public function testAProjectWithoutAGitHubRepositoryRaisesAReadFailureBeforeAnyRequest(): void
    {
        try {
            $this->reads()->listOpen('beta', null);
            self::fail('The project has no GitHub repository.');
        } catch (ForgeReadFailed) {
            self::assertSame([], $this->requested);
        }
    }

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-11-25 10:00:00 UTC');
    }

    /**
     * @return list<CiRunState>
     */
    private function statesOfHead(): array
    {
        return array_map(static fn (CiRun $run): CiRunState => $run->state, $this->state()->runs);
    }

    private function state(): CiState
    {
        return $this->reads()->ciStateOf('alpha', new CommitId(self::HEAD));
    }

    private function reads(): GitHubWatchReads
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options): ResponseInterface {
            $this->requested[] = $method.' '.$url;
            $this->options[] = $options;

            return $this->answer($url);
        }, 'https://api.github.com');
        $clock = $this->clock;
        $identity = new RecordedInstallation('installation-secret')->client();
        $jwt = new AppJwt('12345', AppKey::generate()->private, $clock);
        $tokens = new InstallationTokens(new InstallationTokenMinter($jwt, $identity, new NullLogger()), '678', $clock);
        $expected = ExpectedInstallation::fromSettings('contents:write,pull_requests:write,workflows:write', 'acme/widget,acme/gadget');
        $readings = FreshReadings::at($clock);
        $readBack = new InstallationReadBack(new InstallationReader($jwt, $tokens, new GitHubCalls($identity, $readings, $tokens)), $expected, new NullLogger());

        return new GitHubWatchReads(new GitHubCalls($http, $readings, $tokens), new CheckedInstallationTokens($readBack, $tokens), new WatchedRepositories(['alpha' => ['github' => 'acme/widgets'], 'beta' => []]), new GitHubCiRuns(new GitHubMoments(), new MockClock($this->ciNow)), new GitHubMoments(), FreshReadings::reserveOf($readings, $tokens, $clock));
    }

    /**
     * @return list<string>
     */
    private function listingRequests(): array
    {
        return array_values(array_filter($this->requested, static fn (string $request): bool => str_starts_with($request, self::PULLS)));
    }

    private function answer(string $url): ResponseInterface
    {
        if ($this->failWith > 0) {
            return new MockResponse('{}', ['http_code' => $this->failWith]);
        }

        if (-1 === $this->failWith) {
            throw new TransportException('connection refused');
        }

        return match (true) {
            str_contains($url, '/pulls') => $this->listingPage($url),
            str_contains($url, '/actions/runs') => new MockResponse($this->fixture($this->runs), ['response_headers' => $this->budget()]),
            default => new MockResponse($this->fixture($this->suites), ['response_headers' => $this->budget()]),
        };
    }

    private function listingPage(string $url): MockResponse
    {
        if (304 === $this->listingStatus) {
            return new MockResponse('', ['http_code' => 304]);
        }

        $page = 1 === preg_match('/[?&]page=(\d+)/', $url, $found) ? (int) $found[1] : 1;
        $headers = ['response_headers' => ['ETag' => $this->listingEtag, ...$this->budget()]];

        return new MockResponse($this->pageOf($page), $headers);
    }

    private function pageOf(int $page): string
    {
        if (1 === $page && in_array($this->listing, ['pulls_page_full.json', 'pulls_page_shifted.json', 'pulls_page_full_only.json'], true)) {
            return $this->fixture('pulls_page_full.json');
        }

        return match ($this->listing) {
            'pulls_page_full.json' => $this->fixture('pulls_page_last.json'),
            'pulls_page_full_only.json' => '[]',
            'pulls_endless.json' => $this->fixture('pulls_page_full.json'),
            default => $this->fixture($this->listing),
        };
    }

    /**
     * @param array<mixed> $options
     */
    private function header(array $options, string $name): string
    {
        $headers = $options['normalized_headers'] ?? [];
        $values = is_array($headers) ? ($headers[$name] ?? []) : [];

        return is_array($values) && is_string($values[0] ?? null) ? preg_replace('/^[^:]+: /', '', $values[0]) ?? '' : '';
    }

    private function fixture(string $name): string
    {
        $content = file_get_contents(__DIR__.'/Fixtures/'.$name);

        return false === $content ? throw new LogicException('Missing fixture '.$name) : $content;
    }

    /**
     * @return array<string, string>
     */
    private function budget(): array
    {
        return null === $this->remaining ? [] : [
            'x-ratelimit-limit' => '5000',
            'x-ratelimit-remaining' => (string) $this->remaining,
            'x-ratelimit-reset' => (string) $this->clock->now()->modify('+30 minutes')->getTimestamp(),
        ];
    }
}
