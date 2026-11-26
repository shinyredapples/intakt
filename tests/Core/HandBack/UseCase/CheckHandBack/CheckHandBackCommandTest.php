<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\HandBack\UseCase\CheckHandBack;

use Intakt\Core\Git\Contract\GitCall;
use Intakt\Core\Git\Contract\HardenedGit;
use Intakt\Core\Git\Contract\RepositoryAttributesRefused;
use Intakt\Core\HandBack\Contract\AcceptedHandBack;
use Intakt\Core\HandBack\Contract\HandBack;
use Intakt\Core\HandBack\Contract\HandBackBranchRefused;
use Intakt\Core\HandBack\Contract\HandBackNotChecked;
use Intakt\Core\HandBack\Contract\HandBackRefusal;
use Intakt\Core\HandBack\Contract\RefusedHandBack;
use Intakt\Core\HandBack\Domain\AttributionLines;
use Intakt\Core\HandBack\Domain\BundleHeader;
use Intakt\Core\HandBack\UseCase\CheckHandBack\BundleFile;
use Intakt\Core\HandBack\UseCase\CheckHandBack\BundleSecretScan;
use Intakt\Core\HandBack\UseCase\CheckHandBack\CheckHandBack;
use Intakt\Core\HandBack\UseCase\CheckHandBack\CheckHandBackCommand;
use Intakt\Core\HandBack\UseCase\CheckHandBack\CheckHandBackInput;
use Intakt\Core\HandBack\UseCase\CheckHandBack\CredentialVariableUnset;
use Intakt\Core\HandBack\UseCase\CheckHandBack\FailedHandBack;
use Intakt\Core\HandBack\UseCase\CheckHandBack\GivenHistory;
use Intakt\Core\HandBack\UseCase\CheckHandBack\HandBackRoute;
use Intakt\Core\HandBack\UseCase\CheckHandBack\HandBackRoutes;
use Intakt\Core\HandBack\UseCase\CheckHandBack\IncomingObjects;
use Intakt\Core\HandBack\UseCase\CheckHandBack\PullRequestTextUnreadable;
use Intakt\Core\HandBack\UseCase\CheckHandBack\RepositoryGit;
use Intakt\Core\HandBack\UseCase\CheckHandBack\RouteHandBack;
use Intakt\Core\HandBack\UseCase\CheckHandBack\StrippedCommits;
use Intakt\Plugin\Forge\Contract\JobCredentials;
use Intakt\Tests\Core\HandBack\BuildHistory;
use Intakt\Tests\Core\HandBack\RecordingLedger;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

#[CoversClass(CheckHandBackCommand::class)]
#[UsesClass(CheckHandBackInput::class)]
#[UsesClass(CheckHandBack::class)]
#[UsesClass(RouteHandBack::class)]
#[UsesClass(HandBackRoutes::class)]
#[UsesClass(HandBackRoute::class)]
#[UsesClass(FailedHandBack::class)]
#[UsesClass(BundleFile::class)]
#[UsesClass(GivenHistory::class)]
#[UsesClass(IncomingObjects::class)]
#[UsesClass(RepositoryGit::class)]
#[UsesClass(StrippedCommits::class)]
#[UsesClass(BundleSecretScan::class)]
#[UsesClass(JobCredentials::class)]
#[UsesClass(AcceptedHandBack::class)]
#[UsesClass(RefusedHandBack::class)]
#[UsesClass(HandBack::class)]
#[UsesClass(HandBackRefusal::class)]
#[UsesClass(HandBackNotChecked::class)]
#[UsesClass(AttributionLines::class)]
#[UsesClass(BundleHeader::class)]
#[UsesClass(HardenedGit::class)]
#[UsesClass(GitCall::class)]
#[UsesClass(HandBackBranchRefused::class)]
#[UsesClass(CredentialVariableUnset::class)]
#[UsesClass(PullRequestTextUnreadable::class)]
#[UsesClass(RepositoryAttributesRefused::class)]
final class CheckHandBackCommandTest extends TestCase
{
    private const string CREDENTIAL = 'INTAKT_TEST_MOUNTED_CREDENTIAL';

    private ?Filesystem $files = null;

    private BuildHistory $history;

    private RecordingLedger $ledger;

    private LockFactory $locks;

    public function testAnAcceptedBundlePrintsItsHeadThePushedBaseAndHowManyCommitsWereRewritten(): void
    {
        $base = $this->history->commit('First');
        $this->history->push('task');
        $head = $this->history->commit('Second');

        $tester = $this->executed(['repository' => $this->history->mirror, 'branch' => 'task', 'bundle' => $this->history->bundle($base.'..task'), 'pull-request-text' => $this->text()]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame("accepted {$head}\nbase {$base}\nrewritten 0\n", $tester->getDisplay());
    }

    public function testAnAcceptedBundleOfABranchNotPushedYetPrintsNoBase(): void
    {
        $head = $this->history->commit('First');

        $tester = $this->executed(['repository' => $this->history->mirror, 'branch' => 'task', 'bundle' => $this->history->bundle('main..task'), 'pull-request-text' => $this->text()]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame("accepted {$head}\nbase none\nrewritten 0\n", $tester->getDisplay());
    }

    public function testARefusedBundlePrintsTheReasonAndFails(): void
    {
        $this->history->commit('First');

        $tester = $this->executed(['repository' => $this->history->mirror, 'branch' => 'task', 'bundle' => $this->history->bundle('main', 'task'), 'pull-request-text' => $this->text()]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('refused, sent back to the builder: '.HandBackRefusal::TouchesMain->reason(), $this->flat($tester));
    }

    public function testATokenInThePullRequestTextIsSentBackWithTheReasonAndWithoutItsValue(): void
    {
        $key = 'sk_live_'.bin2hex(random_bytes(12));
        $this->history->commit('First');

        $tester = $this->executed(['repository' => $this->history->mirror, 'branch' => 'task', 'bundle' => $this->history->bundle('main..task'), 'pull-request-text' => $this->text('Uses the key '.$key)]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('refused, sent back to the builder: '.HandBackRefusal::TextHoldsSecret->reason(), $this->flat($tester));
        self::assertStringNotContainsString($key, $tester->getDisplay());
    }

    public function testASecondFailureInARowPrintsTheHandOver(): void
    {
        $this->history->commit('First');
        $input = ['repository' => $this->history->mirror, 'branch' => 'task', 'bundle' => $this->history->bundle('main', 'task'), 'pull-request-text' => $this->text()];

        $this->executed($input);
        $tester = $this->executed($input);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('refused, handed over: '.HandBackRefusal::TouchesMain->reason(), $this->flat($tester));
    }

    public function testAPullRequestTextWithANameLongerThanTheErrorBlockFailsWithItsWholePathAndWritesNothing(): void
    {
        $this->history->commit('First');
        // Longer than the block's 120 columns, so the name is always cut, whatever the checkout path or terminal.
        $missing = $this->history->folder.'/'.str_repeat('missing-', 25).'text.md';

        $tester = $this->executed(['repository' => $this->history->mirror, 'branch' => 'task', 'bundle' => $this->history->bundle('main..task'), 'pull-request-text' => $missing]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString($this->unwrapped('"'.$missing.'" cannot be read'), $this->unwrapped($tester->getDisplay()));
        self::assertSame([], $this->ledger->kinds());
    }

    public function testAMergeOfMainPassesOnlyWithTheOption(): void
    {
        $this->history->commit('First');
        $this->history->checkout('main');
        $this->history->commit('On main');
        $this->history->push('main');
        $this->history->checkout('task');
        $this->history->merge('main');
        $bundle = $this->history->bundle('task', '^main');

        $without = $this->executed(['repository' => $this->history->mirror, 'branch' => 'task', 'bundle' => $bundle, 'pull-request-text' => $this->text()]);
        $with = $this->executed(['repository' => $this->history->mirror, 'branch' => 'task', 'bundle' => $bundle, 'pull-request-text' => $this->text(), '--merge-main' => true]);

        self::assertSame(Command::FAILURE, $without->getStatusCode());
        self::assertSame(Command::SUCCESS, $with->getStatusCode());
    }

    public function testABranchThatIsMainFailsWithTheReason(): void
    {
        $tester = $this->executed(['repository' => $this->history->mirror, 'branch' => 'main', 'bundle' => $this->history->file(''), 'pull-request-text' => $this->text()]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('is main itself', $this->flat($tester));
    }

    public function testAFolderThatIsNoRepositoryFailsWithTheReason(): void
    {
        $tester = $this->executed(['repository' => $this->history->folder, 'branch' => 'task', 'bundle' => $this->history->file(''), 'pull-request-text' => $this->text()]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('could not be checked', $this->flat($tester));
    }

    public function testACredentialNamedByItsVariableIsRedactedFromTheCommits(): void
    {
        $credential = 'mounted-'.bin2hex(random_bytes(8));
        $this->history->commit("Read with {$credential}");
        $_SERVER[self::CREDENTIAL] = $credential;

        try {
            $tester = $this->executed(['repository' => $this->history->mirror, 'branch' => 'task', 'bundle' => $this->history->bundle('main..task'), 'pull-request-text' => $this->text(), '--credential-env' => [self::CREDENTIAL]]);
        } finally {
            unset($_SERVER[self::CREDENTIAL]);
        }

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringEndsWith("rewritten 1\n", $tester->getDisplay());
        self::assertStringNotContainsString($credential, $this->history->inMirror('log', '--format=%B', explode(' ', explode("\n", $tester->getDisplay())[0])[1]));
    }

    public function testACredentialVariableThatIsUnsetFailsWithItsName(): void
    {
        $this->history->commit('First');

        $tester = $this->executed(['repository' => $this->history->mirror, 'branch' => 'task', 'bundle' => $this->history->bundle('main..task'), 'pull-request-text' => $this->text(), '--credential-env' => [self::CREDENTIAL]]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('the credential variable "'.self::CREDENTIAL.'" is unset or empty', $this->flat($tester));
    }

    public function testABranchNameGitRefusesFailsWithTheReason(): void
    {
        $tester = $this->executed(['repository' => $this->history->mirror, 'branch' => 'a..b', 'bundle' => $this->history->file(''), 'pull-request-text' => $this->text()]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('"a..b" is not a branch name git accepts', $this->flat($tester));
    }

    public function testAMirrorHoldingInfoAttributesFailsWithTheRefusal(): void
    {
        $this->history->commit('Build work');
        $bundle = $this->history->bundle('main..task');
        new Filesystem()->dumpFile($this->history->mirror.'/info/attributes', "* -text\n");

        $tester = $this->executed(['repository' => $this->history->mirror, 'branch' => 'task', 'bundle' => $bundle, 'pull-request-text' => $this->text()]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('info/attributes exists: git reads it', $this->flat($tester));
    }

    public function testAnUnexpectedInvalidArgumentExceptionPassesThroughTheCommand(): void
    {
        $files = self::createStub(Filesystem::class);
        $files->method('readFile')->willThrowException(new InvalidArgumentException('a bug in reading'));
        $this->files = $files;

        $this->expectExceptionObject(new InvalidArgumentException('a bug in reading'));

        $this->executed(['repository' => $this->history->mirror, 'branch' => 'task', 'bundle' => $this->history->file(''), 'pull-request-text' => 'any.md']);
    }

    public function testASecretInTheBundlePrintsTheRefusalWithoutTheSecret(): void
    {
        $key = 'sk_live_'.bin2hex(random_bytes(12));
        $this->history->commit('Use the key '.$key);

        $tester = $this->executed(['repository' => $this->history->mirror, 'branch' => 'task', 'bundle' => $this->history->bundle('main..task'), 'pull-request-text' => $this->text()]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('refused, sent back to the builder: '.HandBackRefusal::HoldsSecret->reason(), $this->flat($tester));
        self::assertStringNotContainsString($key, $tester->getDisplay());
    }

    protected function setUp(): void
    {
        $this->history = new BuildHistory();
        $this->ledger = new RecordingLedger();
        $this->locks = new LockFactory(new InMemoryStore());
    }

    protected function tearDown(): void
    {
        $this->history->remove();
    }

    private function text(string $content = 'Adds the change.'): string
    {
        $path = $this->history->folder.'/'.bin2hex(random_bytes(4)).'.md';
        new Filesystem()->dumpFile($path, $content);

        return $path;
    }

    /**
     * @param array<string, string|bool|list<string>> $input
     */
    private function executed(array $input): CommandTester
    {
        $scan = new BundleSecretScan('gitleaks');
        $checks = new CheckHandBack(new RepositoryGit(new HardenedGit()), new BundleFile(), new StrippedCommits(), new IncomingObjects(), $scan);
        $application = new Application();
        $application->addCommand(new CheckHandBackCommand(new RouteHandBack($checks, $scan, new HandBackRoutes($this->ledger, $this->locks)), $this->files ?? new Filesystem()));
        $tester = new CommandTester($application->find('intakt:hand-back:check'));
        $tester->execute($input);

        return $tester;
    }

    private function flat(CommandTester $tester): string
    {
        return (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
    }

    // Every space goes, because the block cuts a long word anywhere and indents the rest.
    private function unwrapped(string $text): string
    {
        return (string) preg_replace('/\s+/', '', $text);
    }
}
