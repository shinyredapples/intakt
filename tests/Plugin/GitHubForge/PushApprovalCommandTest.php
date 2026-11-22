<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubForge;

use Intakt\Core\Git\Contract\GitCall;
use Intakt\Core\Git\Contract\HardenedGit;
use Intakt\Plugin\Forge\Contract\BinaryFiles;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\Forge\Contract\ForgeWriteFailed;
use Intakt\Plugin\GitHubForge\Contract\GitHubForgeServices;
use Intakt\Plugin\GitHubForge\Domain\ChangeNotShown;
use Intakt\Plugin\GitHubForge\Domain\CiDefinition;
use Intakt\Plugin\GitHubForge\Domain\CiFinding;
use Intakt\Plugin\GitHubForge\Domain\CiScan;
use Intakt\Plugin\GitHubForge\Domain\CiScanResult;
use Intakt\Plugin\GitHubForge\Domain\CiWorkflows;
use Intakt\Plugin\GitHubForge\Domain\ContainedTrigger;
use Intakt\Plugin\GitHubForge\Domain\HeldChange;
use Intakt\Plugin\GitHubForge\Domain\HostedRunnerLabel;
use Intakt\Plugin\GitHubForge\Domain\IssueComment;
use Intakt\Plugin\GitHubForge\Domain\PushApproval;
use Intakt\Plugin\GitHubForge\Domain\PushRequest;
use Intakt\Plugin\GitHubForge\Domain\SecretReferences;
use Intakt\Plugin\GitHubForge\Domain\TaskIssue;
use Intakt\Plugin\GitHubForge\Domain\TokenPermissions;
use Intakt\Plugin\GitHubForge\Domain\WorkflowBlockValue;
use Intakt\Plugin\GitHubForge\Domain\WorkflowEntry;
use Intakt\Plugin\GitHubForge\Domain\WorkflowFile;
use Intakt\Plugin\GitHubForge\Domain\WorkflowFileScan;
use Intakt\Plugin\GitHubForge\Domain\WorkflowFinding;
use Intakt\Plugin\GitHubForge\Domain\WorkflowFormNotRead;
use Intakt\Plugin\GitHubForge\Domain\WorkflowInlineValue;
use Intakt\Plugin\GitHubForge\Domain\WorkflowLine;
use Intakt\Plugin\GitHubForge\Domain\WorkflowMapping;
use Intakt\Plugin\GitHubForge\Domain\WorkflowReader;
use Intakt\Plugin\GitHubForge\Domain\WorkflowRisk;
use Intakt\Plugin\GitHubForge\Domain\WorkflowScalar;
use Intakt\Plugin\GitHubForge\Domain\WorkflowSequence;
use Intakt\Plugin\GitHubForge\Domain\WorkflowSide;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubIssueComments;
use Intakt\Plugin\GitHubForge\Infrastructure\MirrorChange;
use Intakt\Plugin\GitHubForge\Infrastructure\MirrorWorkflows;
use Intakt\Plugin\GitHubForge\Infrastructure\SecretScan;
use Intakt\Plugin\GitHubForge\UseCase\ApprovePush\PushApprovalCommand;
use Intakt\Plugin\GitHubForge\UseCase\ApprovePush\PushApprovalInput;
use Intakt\Plugin\GitHubForge\UseCase\ApprovePush\PushDecision;
use Intakt\Plugin\GitHubForge\UseCase\ApprovePush\PushGate;
use Intakt\Plugin\GitHubForge\UseCase\ApprovePush\PushOutcome;
use Intakt\Tests\Core\Git\TemporaryRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

#[CoversClass(PushApprovalCommand::class)]
#[CoversClass(PushGate::class)]
#[CoversClass(PushDecision::class)]
#[CoversClass(PushOutcome::class)]
#[CoversClass(PushApprovalInput::class)]
#[UsesClass(TaskIssue::class)]
#[UsesClass(MirrorChange::class)]
#[UsesClass(BinaryFiles::class)]
#[UsesClass(MirrorWorkflows::class)]
#[UsesClass(GitHubIssueComments::class)]
#[UsesClass(SecretScan::class)]
#[UsesClass(PushApproval::class)]
#[UsesClass(PushRequest::class)]
#[UsesClass(ChangeNotShown::class)]
#[UsesClass(HeldChange::class)]
#[UsesClass(IssueComment::class)]
#[UsesClass(ForgeReadFailed::class)]
#[UsesClass(ForgeWriteFailed::class)]
#[UsesClass(HardenedGit::class)]
#[UsesClass(GitCall::class)]
#[UsesClass(CiScan::class)]
#[UsesClass(CiScanResult::class)]
#[UsesClass(CiWorkflows::class)]
#[UsesClass(CiFinding::class)]
#[UsesClass(CiDefinition::class)]
#[UsesClass(WorkflowFile::class)]
#[UsesClass(WorkflowFileScan::class)]
#[UsesClass(WorkflowReader::class)]
#[UsesClass(WorkflowInlineValue::class)]
#[UsesClass(WorkflowBlockValue::class)]
#[UsesClass(TokenPermissions::class)]
#[UsesClass(SecretReferences::class)]
#[UsesClass(WorkflowLine::class)]
#[UsesClass(WorkflowEntry::class)]
#[UsesClass(WorkflowMapping::class)]
#[UsesClass(WorkflowScalar::class)]
#[UsesClass(WorkflowSequence::class)]
#[UsesClass(WorkflowFinding::class)]
#[UsesClass(WorkflowFormNotRead::class)]
#[UsesClass(WorkflowRisk::class)]
#[UsesClass(WorkflowSide::class)]
#[UsesClass(HostedRunnerLabel::class)]
#[UsesClass(ContainedTrigger::class)]
final class PushApprovalCommandTest extends TestCase
{
    private const string VARIABLE_RUNNER = "name: checks\non: push\npermissions:\n  contents: read\njobs:\n  check:\n    runs-on: \${{ vars.RUNNER }}\n    steps:\n      - run: bin/check\n";

    private TemporaryRepository $repository;
    private ScriptedIssue $issue;
    private LockFactory $locks;

    protected function setUp(): void
    {
        $this->repository = new TemporaryRepository();
        $this->repository->commitFile('.github/workflows/checks.yml', $this->fixture('contained.yml'));
        $this->repository->commitFile('bin/check', "#!/bin/sh\n");
        $this->repository->checkoutNewBranch('head');
        $this->issue = new ScriptedIssue();
        $this->locks = new LockFactory(new InMemoryStore());
    }

    protected function tearDown(): void
    {
        $this->repository->remove();
    }

    public function testACiChangeInsideTheEnvelopePushesWithoutAskingTheOwner(): void
    {
        $this->repository->commitFile('bin/check', "#!/bin/sh\nexit 0\n");

        $tester = $this->execute();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame("ci bin/check\npush: free (no risky CI change)\n", $tester->getDisplay());
        self::assertSame([], $this->issue->requests);
    }

    public function testARiskyWorkflowChangeStaysLocalAndTheIssueShowsItsCompleteDiff(): void
    {
        $this->repository->commitFile('.github/workflows/deploy.yml', $this->fixture('risky.yml'));
        $head = $this->repository->commitFile('deploy.sh', "#!/bin/sh\ncurl example.invalid\n");

        $tester = $this->execute();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringEndsWith(sprintf("push: held, the owner was asked on issue #7 to approve %s\n", $head), $tester->getDisplay());
        self::assertCount(1, $this->issue->posted());
        self::assertStringContainsString("```diff\ndiff --git a/.github/workflows/deploy.yml b/.github/workflows/deploy.yml\n", $this->issue->posted()[0]);
        self::assertStringContainsString("\n+".str_replace("\n", "\n+", rtrim($this->fixture('risky.yml')))."\n", $this->issue->posted()[0]);
        self::assertStringContainsString("diff --git a/deploy.sh b/deploy.sh\n", $this->issue->posted()[0]);
        self::assertStringContainsString("+#!/bin/sh\n+curl example.invalid\n```", $this->issue->posted()[0]);
        self::assertStringContainsString('    approve '.$head, $this->issue->posted()[0]);
    }

    public function testARunWhileTheRequestStandsPostsNoSecondOne(): void
    {
        $head = $this->repository->commitFile('.github/workflows/deploy.yml', $this->fixture('risky.yml'));
        $this->execute();

        $tester = $this->execute();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringEndsWith(sprintf("push: held, still waiting for the owner's approve %s on issue #7\n", $head), $tester->getDisplay());
        self::assertCount(1, $this->issue->posted());
    }

    public function testTheOwnersApproveOfExactlyThisHeadLetsItPush(): void
    {
        $head = $this->repository->commitFile('.github/workflows/deploy.yml', $this->fixture('risky.yml'));
        $this->execute();
        $this->issue->byPerson('approve '.$head);

        $tester = $this->execute();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringEndsWith(sprintf("push: approved by the owner for %s\n", $head), $tester->getDisplay());
    }

    public function testAnApprovalOfAnEarlierHeadDoesNotLetANewHeadPush(): void
    {
        $earlier = $this->repository->commitFile('.github/workflows/deploy.yml', $this->fixture('risky.yml'));
        $this->issue->byPerson('approve '.$earlier);
        $this->repository->commitFile('deploy.sh', "#!/bin/sh\n");

        $tester = $this->execute();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertCount(1, $this->issue->posted());
    }

    public function testAnApproveGivenBeforeTheRequestWasPostedLeavesTheHeadHeld(): void
    {
        $head = $this->repository->commitFile('.github/workflows/deploy.yml', $this->fixture('risky.yml'));
        $this->issue->byPerson('approve '.$head);
        $this->execute();

        $tester = $this->execute();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringEndsWith(sprintf("push: held, still waiting for the owner's approve %s on issue #7\n", $head), $tester->getDisplay());
    }

    public function testAHeadsMarkerInsideAnotherHeadsDiffIsNoRequestForIt(): void
    {
        $target = $this->repository->commitFile('.github/workflows/deploy.yml', $this->fixture('risky.yml'));
        $later = $this->repository->commitFile('notes.txt', new PushRequest()->marker($target)."\n");
        $this->execute($later);
        $this->issue->byPerson('approve '.$target);

        $tester = $this->execute($target);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringEndsWith(sprintf("push: held, the owner was asked on issue #7 to approve %s\n", $target), $tester->getDisplay());
        self::assertCount(2, $this->issue->posted());
    }

    public function testARequestWhoseTextWasReplacedIsNoRequestForTheHead(): void
    {
        $head = $this->repository->commitFile('.github/workflows/deploy.yml', $this->fixture('risky.yml'));
        $this->execute();
        $this->issue->comments[0]['body'] = "Only a harmless README edit.\n".new PushRequest()->marker($head);
        $this->issue->comments[0]['updated_at'] = '2026-11-25T10:05:00Z';
        $this->issue->byPerson('approve '.$head);

        $tester = $this->execute();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringEndsWith(sprintf("push: held, the owner was asked on issue #7 to approve %s\n", $head), $tester->getDisplay());
        self::assertCount(2, $this->issue->posted());
    }

    public function testARequestEditedBackToItsTextIsNoRequestForTheHead(): void
    {
        $head = $this->repository->commitFile('.github/workflows/deploy.yml', $this->fixture('risky.yml'));
        $this->execute();
        $this->issue->comments[0]['updated_at'] = '2026-11-25T10:05:00Z';
        $this->issue->byPerson('approve '.$head);

        $tester = $this->execute();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringEndsWith(sprintf("push: held, the owner was asked on issue #7 to approve %s\n", $head), $tester->getDisplay());
        self::assertCount(2, $this->issue->posted());
    }

    public function testARiskyChangeThatAddsAPngIsAskedForWithThePngNamedByItsSizeAndBlobIds(): void
    {
        $this->repository->commitFile('.github/workflows/deploy.yml', $this->fixture('risky.yml'));
        $head = $this->repository->commitFile('assets/logo.png', "\x89PNG\r\n\x1a\n\0\0\0\rIHDR\xff\xd8");
        $blob = $this->repository->objectAt($head.':assets/logo.png');

        $tester = $this->execute();

        self::assertStringEndsWith(sprintf("push: held, the owner was asked on issue #7 to approve %s\n", $head), $tester->getDisplay());
        self::assertStringContainsString("diff --git a/assets/logo.png b/assets/logo.png\nnew file mode 100644\nindex 0000000000000000000000000000000000000000..".$blob."\nBinary file, not shown: absent at the base, 18 bytes at the head\n", $this->issue->posted()[0]);
    }

    public function testAnApprovedChangeTheIssueCannotShowStaysHeldAndPostsNothing(): void
    {
        $this->repository->commitFile('.github/workflows/deploy.yml', $this->fixture('risky.yml'));
        $head = $this->repository->commitFile('notes.txt', "caf\xe9\n");
        $this->issue->byPerson('approve '.$head);

        $tester = $this->execute();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringStartsWith('push: held, the change cannot be shown in full and goes back to be split', $tester->getDisplay());
        self::assertSame([], $this->issue->posted());
    }

    public function testARiskyChangeThatAddsAScriptWithANulByteStaysHeldAndPostsNothing(): void
    {
        $this->repository->commitFile('.github/workflows/deploy.yml', $this->fixture('risky.yml'));
        $this->repository->commitFile('tools/run.sh', "echo payload-ran\n# \0\n");

        $tester = $this->execute();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringStartsWith('push: held, the change cannot be shown in full and goes back to be split: the diff holds a NUL byte', $tester->getDisplay());
        self::assertSame([], $this->issue->posted());
    }

    public function testAnotherAccountsApprovalLeavesTheHeadHeld(): void
    {
        $head = $this->repository->commitFile('.github/workflows/deploy.yml', $this->fixture('risky.yml'));
        $this->issue->byPerson('approve '.$head, 'maintainer');

        self::assertSame(Command::FAILURE, $this->execute()->getStatusCode());
    }

    public function testAnApproveAnAppPostedWithTheOwnersTokenLeavesTheHeadHeld(): void
    {
        $head = $this->repository->commitFile('.github/workflows/deploy.yml', $this->fixture('risky.yml'));
        $this->issue->byOwnerThroughAnApp('approve '.$head, (int) ScriptedIssue::APP_ID);
        $this->issue->byOwnerThroughAnApp('approve '.$head, 999);

        self::assertSame(Command::FAILURE, $this->execute()->getStatusCode());
    }

    public function testAScriptChangeUnderARunnerFromAVariableIsHeldWithNoWorkflowChanged(): void
    {
        $this->repository->checkout('main');
        $this->repository->commitFile('.github/workflows/checks.yml', self::VARIABLE_RUNNER);
        $this->repository->checkout('head');
        $head = $this->repository->commitFile('bin/check', "#!/bin/sh\nexit 0\n");

        $tester = $this->execute();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('main .github/workflows/checks.yml line 7: unreadable: the job check takes its runner from an expression the scan does not read', $tester->getDisplay());
        self::assertStringContainsString("ci bin/check\n", $tester->getDisplay());
        self::assertStringEndsWith(sprintf("push: held, the owner was asked on issue #7 to approve %s\n", $head), $tester->getDisplay());
    }

    public function testAFailedCommentReadHoldsThePush(): void
    {
        $this->repository->commitFile('.github/workflows/deploy.yml', $this->fixture('risky.yml'));
        $this->issue->refusal = 502;

        $tester = $this->execute();

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertSame("push: held, nothing was decided: GitHub answered 502 to a read of the comments.\n", $tester->getDisplay());
    }

    public function testARequestGitHubStoresWithoutThisAppsMarkHoldsThePushAndSaysSo(): void
    {
        $this->repository->commitFile('.github/workflows/deploy.yml', $this->fixture('risky.yml'));
        $this->issue->storedOtherwise = ['performed_via_github_app' => null];

        $tester = $this->execute();

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertSame("push: held, nothing was decided: GitHub stored the comment without this App's mark, so no run would find it as the request.\n", $tester->getDisplay());
    }

    public function testAHeadThatIsNoCommitHoldsThePush(): void
    {
        $tester = $this->execute('missing');

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertSame("push: held, nothing was decided: missing names no commit of the repository\n", $tester->getDisplay());
    }

    public function testADiffWithASecretIsNotPostedAndGoesBack(): void
    {
        $this->repository->commitFile('.github/workflows/deploy.yml', $this->fixture('risky.yml'));
        $this->repository->commitFile('deploy.pem', AppKey::generate()->private);

        $tester = $this->execute();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertSame("push: held, the change cannot be shown in full and goes back to be split: the diff holds text the secret scan finds, so it is not posted\n", $tester->getDisplay());
        self::assertSame([], $this->issue->posted());
    }

    public function testAChangeTooLargeForOneCommentIsNotPostedAndGoesBack(): void
    {
        $this->repository->commitFile('.github/workflows/deploy.yml', $this->fixture('risky.yml'));
        $this->repository->commitFile('data.txt', str_repeat("line\n", intdiv(PushRequest::MAX_CHARACTERS, 5)));

        $tester = $this->execute();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('more than one comment holds (65536), so the change must be split', $tester->getDisplay());
        self::assertSame([], $this->issue->posted());
    }

    public function testASecondRunWhileOneAsksTheOwnerHoldsThePushAndPostsNothing(): void
    {
        $head = $this->repository->commitFile('.github/workflows/deploy.yml', $this->fixture('risky.yml'));
        $busy = $this->locks->createLock(GitHubForgeServices::PUSH_REQUEST_LOCK_PREFIX.'6:widget.7.'.$head);
        $busy->acquire();

        $tester = $this->execute();

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertSame("push: held, nothing was decided: Another run is asking the owner about this head.\n", $tester->getDisplay());
        self::assertSame([], $this->issue->requests);
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__.'/Fixtures/workflows/'.$name);
    }

    private function execute(string $head = 'head'): CommandTester
    {
        $reader = new WorkflowReader(new WorkflowInlineValue(), new WorkflowBlockValue());
        $scan = new CiScan(new WorkflowFileScan($reader, new TokenPermissions(), new SecretReferences()), new CiDefinition($reader));
        $gate = new PushGate(
            new MirrorChange(new HardenedGit(), new BinaryFiles()),
            new MirrorWorkflows(new HardenedGit()),
            $scan,
            $this->issue->comments(),
            new PushApproval(),
            new PushRequest(),
            new SecretScan(dirname(__DIR__, 3), 'gitleaks'),
            $this->locks,
            'owner',
        );
        $application = new Application();
        $application->addCommand(new PushApprovalCommand($gate));
        $tester = new CommandTester($application->find('intakt:forge:push-approval'));
        $this->repository->checkout('main');
        $tester->execute(['repository' => $this->repository->path.'/.git', 'head' => $head, '--project' => ScriptedIssue::PROJECT, '--issue' => ScriptedIssue::NUMBER]);

        return $tester;
    }
}
