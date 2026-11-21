<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubForge;

use Intakt\Plugin\Forge\Contract\ChangeId;
use Intakt\Plugin\Forge\Contract\ChangeText;
use Intakt\Plugin\Forge\Contract\ChangeTextHoldsSecret;
use Intakt\Plugin\Forge\Contract\ForgeRateLimited;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\Forge\Contract\ForgeWriteFailed;
use Intakt\Plugin\Forge\Contract\OpenedChange;
use Intakt\Plugin\Forge\Contract\Opening;
use Intakt\Plugin\Forge\Contract\ProposedChange;
use Intakt\Plugin\GitHubForge\Contract\InstallationToken;
use Intakt\Plugin\GitHubForge\Contract\TrustNotChecked;
use Intakt\Plugin\GitHubForge\Domain\ExpectedInstallation;
use Intakt\Plugin\GitHubForge\Domain\Installation;
use Intakt\Plugin\GitHubForge\Infrastructure\AppJwt;
use Intakt\Plugin\GitHubForge\Infrastructure\CheckedInstallationTokens;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubCalls;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubChangeOpener;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationReadBack;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationReader;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationTokenMinter;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationTokens;
use Intakt\Plugin\GitHubForge\Infrastructure\RateLimitReadings;
use Intakt\Plugin\GitHubForge\Infrastructure\RepositoryAddress;
use Intakt\Plugin\GitHubForge\Infrastructure\SecretScan;
use Intakt\Plugin\GitHubForge\Infrastructure\SteeringIdentities;
use Intakt\Plugin\GitHubForge\Infrastructure\TrustAnswers;
use Intakt\Plugin\GitHubForge\Infrastructure\WatchedRepositories;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(GitHubChangeOpener::class)]
#[UsesClass(RepositoryAddress::class)]
#[UsesClass(WatchedRepositories::class)]
#[UsesClass(SecretScan::class)]
#[UsesClass(ChangeId::class)]
#[UsesClass(OpenedChange::class)]
#[UsesClass(ChangeText::class)]
#[UsesClass(ProposedChange::class)]
#[UsesClass(ChangeTextHoldsSecret::class)]
#[UsesClass(ForgeReadFailed::class)]
#[UsesClass(ForgeRateLimited::class)]
#[UsesClass(ForgeWriteFailed::class)]
#[UsesClass(RateLimitReadings::class)]
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
#[UsesClass(SteeringIdentities::class)]
#[UsesClass(TrustAnswers::class)]
#[UsesClass(TrustNotChecked::class)]
final class GitHubChangeOpenerTest extends TestCase
{
    private const string API = 'https://api.github.com/repos/acme/widgets';

    public function testABranchWithoutAPullRequestGetsOneOpenedWithTheBuildersText(): void
    {
        $github = new ScriptedGitHub();

        $change = $github->opener()->openOrUpdate(new ProposedChange('widgets', 'task', 'main', new ChangeText('Add the files', 'Evidence: tests pass.')));

        self::assertSame('https://github.com/acme/widgets/pull/42', $change->id->value);
        self::assertSame([['POST '.self::API.'/pulls', ['title' => 'Add the files', 'body' => 'Evidence: tests pass.', 'base' => 'main', 'head' => 'task']]], $github->writes);
    }

    public function testAPullRequestTheOpenerPostedIsAnsweredAsCreated(): void
    {
        $github = new ScriptedGitHub();

        $change = $github->opener()->openOrUpdate(new ProposedChange('widgets', 'task', 'main', new ChangeText('Add the files', 'Evidence: tests pass.')));

        self::assertSame(Opening::Created, $change->opening);
    }

    public function testAnOpenPullRequestAPersonOpenedIsLeftAloneBeforeAnyWrite(): void
    {
        $github = new ScriptedGitHub();
        $github->openPulls = [['number' => 7, 'head' => ['ref' => 'task'], 'user' => ['login' => 'octocat']]];

        $this->assertWriteFailsWith('not opened by this App', fn () => $github->opener()->openOrUpdate(new ProposedChange('widgets', 'task', 'main', new ChangeText('New title', 'New body'))));
        self::assertSame([], $github->writes);
    }

    public function testAnOpenPullRequestThisAppCreatedBeforeAnInterruptedPushIsAnsweredAsCreatedOnTheRetry(): void
    {
        $github = new ScriptedGitHub();
        $github->openPulls = [['number' => 7, 'head' => ['ref' => 'task'], 'user' => ['login' => 'intakt[bot]', 'type' => 'Bot']]];

        $change = $github->opener()->openOrUpdate(new ProposedChange('widgets', 'task', 'main', new ChangeText('T', 'B')));

        self::assertSame(Opening::Created, $change->opening);
        self::assertSame('PATCH '.self::API.'/pulls/7', $github->writes[0][0]);
    }

    public function testThisAppsBotAccountIsRecognizedInAnyLetterCaseOfItsSlug(): void
    {
        $github = new ScriptedGitHub();
        $github->openPulls = [['number' => 7, 'head' => ['ref' => 'task'], 'user' => ['login' => 'InTakt[bot]']]];

        $change = $github->opener()->openOrUpdate(new ProposedChange('widgets', 'task', 'main', new ChangeText('T', 'B')));

        self::assertSame(Opening::Created, $change->opening);
    }

    public function testAnOpenPullRequestAnotherAppCreatedIsLeftAloneBeforeAnyWrite(): void
    {
        $github = new ScriptedGitHub();
        $github->openPulls = [['number' => 7, 'head' => ['ref' => 'task'], 'user' => ['login' => 'loop[bot]', 'type' => 'Bot']]];

        $this->assertWriteFailsWith('not opened by this App', fn () => $github->opener()->openOrUpdate(new ProposedChange('widgets', 'task', 'main', new ChangeText('T', 'B'))));
        self::assertSame([], $github->writes);
    }

    public function testAnOpenPullRequestOfAPersonNamedLikeTheAppIsLeftAloneBeforeAnyWrite(): void
    {
        $github = new ScriptedGitHub();
        $github->openPulls = [['number' => 7, 'head' => ['ref' => 'task'], 'user' => ['login' => 'intakt', 'type' => 'User']]];

        $this->assertWriteFailsWith('not opened by this App', fn () => $github->opener()->openOrUpdate(new ProposedChange('widgets', 'task', 'main', new ChangeText('T', 'B'))));
        self::assertSame([], $github->writes);
    }

    public function testAnOpenPullRequestThisAppCreatedIsUpdatedAndAnsweredAsCreated(): void
    {
        $github = new ScriptedGitHub();
        $github->openPulls = [['number' => 7, 'head' => ['ref' => 'task'], 'user' => ['login' => ScriptedGitHub::OWN_LOGIN]]];

        $change = $github->opener()->openOrUpdate(new ProposedChange('widgets', 'task', 'main', new ChangeText('T', 'B')));

        self::assertSame(Opening::Created, $change->opening);
    }

    public function testAnOpenPullRequestWhoseAuthorIsNoTextFailsBeforeAnyWrite(): void
    {
        $github = new ScriptedGitHub();
        $github->openPulls = [['number' => 7, 'head' => ['ref' => 'task'], 'user' => ['login' => ['intakt[bot]']]]];

        $this->assertWriteFailsWith('without its author\'s login', fn () => $github->opener()->openOrUpdate(new ProposedChange('widgets', 'task', 'main', new ChangeText('T', 'B'))));
        self::assertSame([], $github->writes);
    }

    public function testARetryThatFindsItsPullRequestWithoutAnAuthorFailsBeforeAnyWrite(): void
    {
        $github = new ScriptedGitHub();
        $proposal = new ProposedChange('widgets', 'task', 'main', new ChangeText('T', 'B'));
        $opener = $github->opener();
        $opener->openOrUpdate($proposal);
        $github->openPulls = [['number' => 42, 'head' => ['ref' => 'task'], 'user' => null]];

        $this->assertWriteFailsWith('without its author\'s login', fn () => $opener->openOrUpdate($proposal));
        self::assertCount(1, $github->writes);
    }

    public function testARetryThatFindsItsPullRequestWithAnEmptyAuthorLoginFailsBeforeAnyWrite(): void
    {
        $github = new ScriptedGitHub();
        $github->openPulls = [['number' => 11, 'head' => ['ref' => 'task'], 'user' => ['login' => '']]];

        $this->assertWriteFailsWith('without its author\'s login', fn () => $github->opener()->openOrUpdate(new ProposedChange('widgets', 'task', 'main', new ChangeText('T', 'B'))));
        self::assertSame([], $github->writes);
    }

    public function testABotAuthorThatCannotBeCheckedAgainstTheAppFailsBeforeAnyWrite(): void
    {
        $github = new ScriptedGitHub();
        $github->appSlug = null;
        $github->openPulls = [['number' => 7, 'head' => ['ref' => 'task'], 'user' => ['login' => 'intakt[bot]']]];

        $this->assertWriteFailsWith('could not be checked against this App', fn () => $github->opener()->openOrUpdate(new ProposedChange('widgets', 'task', 'main', new ChangeText('T', 'B'))));
        self::assertSame([], $github->writes);
    }

    public function testABranchWithAnOpenPullRequestHasItsTextUpdatedInstead(): void
    {
        $github = new ScriptedGitHub();
        $github->openPulls = [['number' => 7, 'head' => ['ref' => 'task'], 'user' => ['login' => ScriptedGitHub::OWN_LOGIN]]];

        $change = $github->opener()->openOrUpdate(new ProposedChange('widgets', 'task', 'main', new ChangeText('New title', 'New body')));

        self::assertSame('https://github.com/acme/widgets/pull/7', $change->id->value);
        self::assertSame([['PATCH '.self::API.'/pulls/7', ['title' => 'New title', 'body' => 'New body', 'base' => 'main']]], $github->writes);
    }

    public function testAnOpenPullRequestOfTheBranchOpenedByAnotherAccountIsLeftAlone(): void
    {
        $github = new ScriptedGitHub();
        $github->openPulls = [['number' => 7, 'head' => ['ref' => 'task'], 'user' => ['login' => 'acme-loop[bot]']]];

        $this->assertWriteFailsWith('not opened by this App', fn () => $github->opener()->openOrUpdate(new ProposedChange('widgets', 'task', 'main', new ChangeText('T', 'B'))));
        self::assertSame([], $github->writes);
    }

    public function testAnOpenPullRequestOfTheBranchWithoutAnAuthorIsLeftAlone(): void
    {
        $github = new ScriptedGitHub();
        $github->openPulls = [['number' => 7, 'head' => ['ref' => 'task']]];

        $this->assertWriteFailsWith('without its author\'s login', fn () => $github->opener()->openOrUpdate(new ProposedChange('widgets', 'task', 'main', new ChangeText('T', 'B'))));
        self::assertSame([], $github->writes);
    }

    public function testABranchWhoseOpenPullRequestAnotherAccountOpenedIsRefusedBeforeThePush(): void
    {
        $github = new ScriptedGitHub();
        $github->openPulls = [['number' => 7, 'head' => ['ref' => 'task'], 'user' => ['login' => 'acme-loop[bot]']]];

        $this->assertWriteFailsWith('not opened by this App', fn () => $github->opener()->refuseForeignChange('widgets', 'task'));
        self::assertSame([], $github->writes);
    }

    public function testABranchWhoseOpenPullRequestHasNoAuthorIsRefusedBeforeThePush(): void
    {
        $github = new ScriptedGitHub();
        $github->openPulls = [['number' => 7, 'head' => ['ref' => 'task']]];

        $this->assertWriteFailsWith('without its author\'s login', fn () => $github->opener()->refuseForeignChange('widgets', 'task'));
    }

    public function testABranchWhoseOpenPullRequestHasAnOwnerThatCannotBeCheckedIsRefusedBeforeThePush(): void
    {
        $github = new ScriptedGitHub();
        $github->openPulls = [['number' => 7, 'head' => ['ref' => 'task'], 'user' => ['login' => ScriptedGitHub::OWN_LOGIN]]];
        $github->appSlug = null;

        $this->assertWriteFailsWith('could not be checked', fn () => $github->opener()->refuseForeignChange('widgets', 'task'));
    }

    public function testABranchWhoseOpenPullRequestThisAppOpenedMayBePushedAndNothingIsWrittenForTheCheck(): void
    {
        $github = new ScriptedGitHub();
        $github->openPulls = [['number' => 7, 'head' => ['ref' => 'task'], 'user' => ['login' => ScriptedGitHub::OWN_LOGIN]]];

        $github->opener()->refuseForeignChange('widgets', 'task');

        self::assertSame([], $github->writes);
    }

    public function testABranchWithAForeignOpenPullRequestToAnotherBaseListedAfterThisAppsOwnIsRefusedBeforeThePush(): void
    {
        $github = new ScriptedGitHub();
        $github->openPulls = [
            ['number' => 7, 'head' => ['ref' => 'task'], 'base' => ['ref' => 'main'], 'user' => ['login' => ScriptedGitHub::OWN_LOGIN]],
            ['number' => 8, 'head' => ['ref' => 'task'], 'base' => ['ref' => 'release'], 'user' => ['login' => 'acme-loop[bot]']],
        ];

        $this->assertWriteFailsWith('not opened by this App', fn () => $github->opener()->refuseForeignChange('widgets', 'task'));
        self::assertSame([], $github->writes);
    }

    public function testAForeignOpenPullRequestListedAfterThisAppsOwnLeavesBothAlone(): void
    {
        $github = new ScriptedGitHub();
        $github->openPulls = [
            ['number' => 7, 'head' => ['ref' => 'task'], 'base' => ['ref' => 'main'], 'user' => ['login' => ScriptedGitHub::OWN_LOGIN]],
            ['number' => 8, 'head' => ['ref' => 'task'], 'base' => ['ref' => 'release'], 'user' => ['login' => 'acme-loop[bot]']],
        ];

        $this->assertWriteFailsWith('not opened by this App', fn () => $github->opener()->openOrUpdate(new ProposedChange('widgets', 'task', 'main', new ChangeText('T', 'B'))));
        self::assertSame([], $github->writes);
    }

    public function testAnOpenPullRequestWithoutAnAuthorListedAfterThisAppsOwnIsRefusedBeforeThePush(): void
    {
        $github = new ScriptedGitHub();
        $github->openPulls = [
            ['number' => 7, 'head' => ['ref' => 'task'], 'user' => ['login' => ScriptedGitHub::OWN_LOGIN]],
            ['number' => 8, 'head' => ['ref' => 'task'], 'base' => ['ref' => 'release']],
        ];

        $this->assertWriteFailsWith('without its author\'s login',fn () => $github->opener()->refuseForeignChange('widgets', 'task'));
    }

    public function testABranchWhoseOpenPullRequestsAreAllThisAppsOwnHasTheFirstUpdated(): void
    {
        $github = new ScriptedGitHub();
        $github->openPulls = [
            ['number' => 7, 'head' => ['ref' => 'task'], 'base' => ['ref' => 'main'], 'user' => ['login' => ScriptedGitHub::OWN_LOGIN]],
            ['number' => 8, 'head' => ['ref' => 'task'], 'base' => ['ref' => 'release'], 'user' => ['login' => ScriptedGitHub::OWN_LOGIN]],
        ];

        $change = $github->opener()->openOrUpdate(new ProposedChange('widgets', 'task', 'main', new ChangeText('T', 'B')));

        self::assertSame('https://github.com/acme/widgets/pull/7', $change->id->value);
    }

    public function testABranchWithoutAnOpenPullRequestMayBePushed(): void
    {
        $github = new ScriptedGitHub();
        $github->openPulls = [['number' => 7, 'head' => ['ref' => 'other'], 'user' => ['login' => 'acme-loop[bot]']]];

        $github->opener()->refuseForeignChange('widgets', 'task');

        self::assertSame([], $github->writes);
    }

    public function testAProjectWithoutAGitHubRepositoryIsRefusedBeforeThePush(): void
    {
        $this->assertWriteFailsWith('has no GitHub repository', fn () => new ScriptedGitHub()->opener()->refuseForeignChange('unknown', 'task'));
    }

    public function testABranchIsRefusedBeforeThePushAsAFailedWriteWhileThePullRequestLookUpIsRateLimited(): void
    {
        $github = new ScriptedGitHub();
        $github->rateLimitedEnding = '/pulls';

        $this->assertWriteFailsWith('rate limited until', fn () => $github->opener()->refuseForeignChange('widgets', 'task'));
    }

    public function testAPullRequestIsNeitherOpenedNorUpdatedButFailsAsAWriteWhileGitHubIsRateLimited(): void
    {
        $github = new ScriptedGitHub();
        $github->rateLimitedEnding = '/pulls';

        $this->assertWriteFailsWith('rate limited until', fn () => $github->opener()->openOrUpdate(new ProposedChange('widgets', 'task', 'main', new ChangeText('T', 'B'))));
        self::assertSame([], $github->writes);
    }

    public function testAnOpenPullRequestOfThisAppIsKnownWhateverTheCaseOfItsLogin(): void
    {
        $github = new ScriptedGitHub();
        $github->openPulls = [['number' => 7, 'head' => ['ref' => 'task'], 'user' => ['login' => 'INTAKT[bot]']]];

        $github->opener()->openOrUpdate(new ProposedChange('widgets', 'task', 'main', new ChangeText('T', 'B')));

        self::assertSame('PATCH '.self::API.'/pulls/7', $github->writes[0][0]);
    }

    public function testAnOwnerThatCannotBeCheckedLeavesThePullRequestAlone(): void
    {
        $github = new ScriptedGitHub();
        $github->openPulls = [['number' => 7, 'head' => ['ref' => 'task'], 'user' => ['login' => ScriptedGitHub::OWN_LOGIN]]];
        $github->appSlug = null;

        $this->assertWriteFailsWith('could not be checked', fn () => $github->opener()->openOrUpdate(new ProposedChange('widgets', 'task', 'main', new ChangeText('T', 'B'))));
        self::assertSame([], $github->writes);
    }

    public function testAPullRequestOfAnotherBranchIsNotTakenForTheBranchsOwn(): void
    {
        $github = new ScriptedGitHub();
        $github->openPulls = [['number' => 7, 'head' => ['ref' => 'other']]];

        $github->opener()->openOrUpdate(new ProposedChange('widgets', 'task', 'main', new ChangeText('T', 'B')));

        self::assertSame('POST '.self::API.'/pulls', $github->writes[0][0]);
    }

    public function testTextThatHoldsASecretIsRefusedBeforeAnyWrite(): void
    {
        $github = new ScriptedGitHub();
        // Built at run time, because a whole token in this file would trip the repository's own secret scan.
        $secret = 'ghp_'.bin2hex(random_bytes(18));

        try {
            $github->opener()->openOrUpdate(new ProposedChange('widgets', 'task', 'main', new ChangeText('Title', 'The token is '.$secret)));
            self::fail('A secret in the text must be refused.');
        } catch (ChangeTextHoldsSecret) {
            self::assertSame([], $github->writes);
        }
    }

    public function testAProjectWithoutAGitHubRepositoryIsRefusedAsAFailedWrite(): void
    {
        $github = new ScriptedGitHub();

        $this->assertWriteFailsWith('has no GitHub repository', fn () => $github->opener()->openOrUpdate(new ProposedChange('unknown', 'task', 'main', new ChangeText('T', 'B'))));
    }

    public function testAnOpenPullRequestWithoutANumberIsRefused(): void
    {
        $github = new ScriptedGitHub();
        $github->openPulls = [['number' => 'seven', 'head' => ['ref' => 'task'], 'user' => ['login' => ScriptedGitHub::OWN_LOGIN]]];

        $this->expectException(ForgeWriteFailed::class);

        $github->opener()->openOrUpdate(new ProposedChange('widgets', 'task', 'main', new ChangeText('T', 'B')));
    }

    public function testARefusedWriteIsAFailedWriteAndLeavesNothingToRetryBlindly(): void
    {
        $github = new ScriptedGitHub();
        $github->refuseOnce('/pulls', 422);

        $this->assertWriteFailsWith('422', fn () => $github->opener()->openOrUpdate(new ProposedChange('widgets', 'task', 'main', new ChangeText('T', 'B'))));
    }

    public function testAnUnreachableGitHubIsAFailedWriteThatKeepsNothingOfTheFailedRequest(): void
    {
        $github = new ScriptedGitHub();
        $github->unreachable = true;

        try {
            $github->opener()->openOrUpdate(new ProposedChange('widgets', 'task', 'main', new ChangeText('T', 'B')));
            self::fail('An unreachable GitHub must fail the write.');
        } catch (ForgeWriteFailed $failed) {
            self::assertStringContainsString('could not be reached', $failed->getMessage());
            self::assertNull($failed->getPrevious());
        }
    }

    private function assertWriteFailsWith(string $reason, callable $write): void
    {
        try {
            $write();
            self::fail('The write must fail.');
        } catch (ForgeWriteFailed $failed) {
            self::assertStringContainsString($reason, $failed->getMessage());
        }
    }
}
