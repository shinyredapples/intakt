<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Git\UseCase\PushBranch;

use Intakt\Core\Git\Contract\BranchNotPushed;
use Intakt\Core\Git\Contract\BranchPushRequest;
use Intakt\Core\Git\Contract\HardenedGit;
use Intakt\Core\Git\Contract\PushedBranch;
use Intakt\Core\Git\Domain\CommitObject;
use Intakt\Core\Git\UseCase\PushBranch\BranchGit;
use Intakt\Core\Git\UseCase\PushBranch\GuardedRun;
use Intakt\Core\Git\UseCase\PushBranch\PushBranch;
use Intakt\Core\Git\UseCase\PushBranch\PushRecords;
use Intakt\Core\Git\UseCase\PushBranch\SignedCommits;
use Intakt\Core\Git\UseCase\PushBranch\SigningKey;
use Intakt\Core\HandBack\Contract\AcceptedHandBack;
use Intakt\Core\Ledger\Contract\AppendResult;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\RecordedEvent;
use Intakt\Core\Ledger\Contract\RecordedEvents;
use Intakt\Plugin\Forge\Contract\ChangeId;
use Intakt\Plugin\Forge\Contract\ChangeText;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\Forge\Contract\ForgeWriteFailed;
use Intakt\Plugin\Forge\Contract\OpenedChange;
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
use Intakt\Tests\Core\Git\TemporaryRepository;
use Intakt\Tests\Plugin\GitHubForge\ScriptedGitHub;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PushBranch::class)]
#[CoversClass(GitHubChangeOpener::class)]
#[UsesClass(PushRecords::class)]
#[UsesClass(BranchGit::class)]
#[UsesClass(GuardedRun::class)]
#[UsesClass(SignedCommits::class)]
#[UsesClass(SigningKey::class)]
#[UsesClass(CommitObject::class)]
#[UsesClass(BranchPushRequest::class)]
#[UsesClass(PushedBranch::class)]
#[UsesClass(BranchNotPushed::class)]
#[UsesClass(HardenedGit::class)]
#[UsesClass(AcceptedHandBack::class)]
#[UsesClass(LedgerEvent::class)]
#[UsesClass(AppendResult::class)]
#[UsesClass(RecordedEvent::class)]
#[UsesClass(RecordedEvents::class)]
#[UsesClass(TemporaryRepository::class)]
#[UsesClass(ChangeId::class)]
#[UsesClass(ChangeText::class)]
#[UsesClass(OpenedChange::class)]
#[UsesClass(ProposedChange::class)]
#[UsesClass(ForgeReadFailed::class)]
#[UsesClass(ForgeWriteFailed::class)]
#[UsesClass(RepositoryAddress::class)]
#[UsesClass(WatchedRepositories::class)]
#[UsesClass(SecretScan::class)]
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
final class PushToAStubbedGitHubTest extends TestCase
{
    private PushScenario $scenario;
    private ScriptedGitHub $github;

    public function testABranchWhoseForeignPullRequestIsListedAfterThisAppsOwnIsNotPushed(): void
    {
        $this->github->openPulls = [
            ['number' => 7, 'head' => ['ref' => PushScenario::BRANCH], 'base' => ['ref' => 'main'], 'user' => ['login' => 'intakt[bot]']],
            ['number' => 8, 'head' => ['ref' => PushScenario::BRANCH], 'base' => ['ref' => 'release'], 'user' => ['login' => 'loop[bot]']],
        ];

        try {
            (void) $this->scenario->pushThrough($this->github->opener())->push($this->scenario->request());
            self::fail('A branch with a foreign open pull request must not be pushed.');
        } catch (ForgeWriteFailed $refused) {
            self::assertStringContainsString('another account opened', $refused->getMessage());
        }

        self::assertNull($this->scenario->remoteTip());
        self::assertSame([], $this->scenario->ledger->kinds());
        self::assertSame([], $this->github->writes);
    }

    public function testABranchWhoseOpenPullRequestIsThisAppsOwnIsPushedAndItsPullRequestUpdated(): void
    {
        $this->github->openPulls = [['number' => 7, 'head' => ['ref' => PushScenario::BRANCH], 'base' => ['ref' => 'main'], 'user' => ['login' => 'intakt[bot]']]];

        $push = $this->scenario->pushThrough($this->github->opener());
        $pushed = $push->push($this->scenario->request());
        self::assertInstanceOf(PushedBranch::class, $pushed);
        $change = $push->openChange($this->scenario->request(), $pushed);

        self::assertSame($pushed->head, $this->scenario->remoteTip());
        self::assertSame('https://github.com/acme/widgets/pull/7', $change->value);
        self::assertSame(['PATCH https://api.github.com/repos/acme/widgets/pulls/7'], array_column($this->github->writes, 0));
        self::assertSame('created', $this->scenario->ledger->payloadOf(LedgerEventKind::ChangeOpened)['opening']);
    }

    public function testAProjectWithoutAGitHubEntryIsNotPushed(): void
    {
        try {
            (void) $this->scenario->pushThrough($this->github->opener('other'))->push($this->scenario->request());
            self::fail('A project without a github entry must not be pushed.');
        } catch (ForgeWriteFailed $refused) {
            self::assertStringContainsString('has no GitHub repository', $refused->getMessage());
        }

        self::assertNull($this->scenario->remoteTip());
        self::assertSame([], $this->scenario->ledger->kinds());
    }

    protected function setUp(): void
    {
        $this->scenario = new PushScenario();
        $this->github = new ScriptedGitHub();
    }

    protected function tearDown(): void
    {
        $this->scenario->remove();
    }
}
