<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubForge;

use Intakt\Plugin\Forge\Contract\ChangeId;
use Intakt\Plugin\Forge\Contract\ChangeText;
use Intakt\Plugin\Forge\Contract\ChangeTextHoldsSecret;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\Forge\Contract\ForgeWriteFailed;
use Intakt\Plugin\GitHubForge\Contract\InstallationToken;
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
use Intakt\Plugin\GitHubForge\Infrastructure\WatchedRepositories;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(GitHubChangeOpener::class)]
#[UsesClass(RepositoryAddress::class)]
#[UsesClass(WatchedRepositories::class)]
#[UsesClass(SecretScan::class)]
#[UsesClass(ChangeId::class)]
#[UsesClass(ChangeText::class)]
#[UsesClass(ChangeTextHoldsSecret::class)]
#[UsesClass(ForgeReadFailed::class)]
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
final class GitHubChangeOpenerTest extends TestCase
{
    private const string API = 'https://api.github.com/repos/acme/widgets';

    public function testABranchWithoutAPullRequestGetsOneOpenedWithTheBuildersText(): void
    {
        $github = new ScriptedGitHub();

        $change = $github->opener()->openOrUpdate('widgets', 'task', 'main', new ChangeText('Add the files', 'Evidence: tests pass.'));

        self::assertSame('https://github.com/acme/widgets/pull/42', $change->value);
        self::assertSame([['POST '.self::API.'/pulls', ['title' => 'Add the files', 'body' => 'Evidence: tests pass.', 'base' => 'main', 'head' => 'task']]], $github->writes);
    }

    public function testABranchWithAnOpenPullRequestHasItsTextUpdatedInstead(): void
    {
        $github = new ScriptedGitHub();
        $github->openPulls = [['number' => 7, 'head' => ['ref' => 'task']]];

        $change = $github->opener()->openOrUpdate('widgets', 'task', 'main', new ChangeText('New title', 'New body'));

        self::assertSame('https://github.com/acme/widgets/pull/7', $change->value);
        self::assertSame([['PATCH '.self::API.'/pulls/7', ['title' => 'New title', 'body' => 'New body', 'base' => 'main']]], $github->writes);
    }

    public function testAPullRequestOfAnotherBranchIsNotTakenForTheBranchsOwn(): void
    {
        $github = new ScriptedGitHub();
        $github->openPulls = [['number' => 7, 'head' => ['ref' => 'other']]];

        $github->opener()->openOrUpdate('widgets', 'task', 'main', new ChangeText('T', 'B'));

        self::assertSame('POST '.self::API.'/pulls', $github->writes[0][0]);
    }

    public function testTextThatHoldsASecretIsRefusedBeforeAnyWrite(): void
    {
        $github = new ScriptedGitHub();
        // Built at run time, because a whole token in this file would trip the repository's own secret scan.
        $secret = 'gh'.'p_'.bin2hex(random_bytes(18));

        try {
            $github->opener()->openOrUpdate('widgets', 'task', 'main', new ChangeText('Title', 'The token is '.$secret));
            self::fail('A secret in the text must be refused.');
        } catch (ChangeTextHoldsSecret) {
            self::assertSame([], $github->writes);
        }
    }

    public function testAProjectWithoutAGitHubRepositoryIsRefusedAsAFailedWrite(): void
    {
        $github = new ScriptedGitHub();

        $this->expectException(ForgeWriteFailed::class);
        $this->expectExceptionMessage('has no GitHub repository');

        $github->opener()->openOrUpdate('unknown', 'task', 'main', new ChangeText('T', 'B'));
    }

    public function testAnOpenPullRequestWithoutANumberIsRefused(): void
    {
        $github = new ScriptedGitHub();
        $github->openPulls = [['number' => 'seven', 'head' => ['ref' => 'task']]];

        $this->expectException(ForgeWriteFailed::class);

        $github->opener()->openOrUpdate('widgets', 'task', 'main', new ChangeText('T', 'B'));
    }

    public function testARefusedWriteIsAFailedWriteAndLeavesNothingToRetryBlindly(): void
    {
        $github = new ScriptedGitHub();
        $github->refuseOnce('/pulls', 422);

        $this->expectException(ForgeWriteFailed::class);
        $this->expectExceptionMessage('422');

        $github->opener()->openOrUpdate('widgets', 'task', 'main', new ChangeText('T', 'B'));
    }
}
