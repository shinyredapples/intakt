<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Git\UseCase\PushBranch;

use Closure;
use Intakt\Core\Git\Contract\BranchNotPushed;
use Intakt\Core\Git\Contract\BranchPushRequest;
use Intakt\Core\Git\Contract\HardenedGit;
use Intakt\Core\Git\Contract\MainConflicts;
use Intakt\Core\Git\Contract\PushedBranch;
use Intakt\Core\Git\Domain\CommitObject;
use Intakt\Core\Git\UseCase\PushBranch\BranchGit;
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
use Intakt\Tests\Core\Git\TemporaryRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PushBranch::class)]
#[CoversClass(PushRecords::class)]
#[CoversClass(BranchGit::class)]
#[UsesClass(SignedCommits::class)]
#[UsesClass(SigningKey::class)]
#[UsesClass(CommitObject::class)]
#[UsesClass(BranchPushRequest::class)]
#[UsesClass(PushedBranch::class)]
#[UsesClass(MainConflicts::class)]
#[UsesClass(BranchNotPushed::class)]
#[UsesClass(HardenedGit::class)]
#[UsesClass(AcceptedHandBack::class)]
#[UsesClass(ChangeText::class)]
#[UsesClass(ChangeId::class)]
#[UsesClass(LedgerEvent::class)]
#[UsesClass(AppendResult::class)]
#[UsesClass(RecordedEvent::class)]
#[UsesClass(RecordedEvents::class)]
#[UsesClass(TemporaryRepository::class)]
final class PushBranchTest extends TestCase
{
    private PushScenario $scenario;

    protected function setUp(): void
    {
        $this->scenario = new PushScenario();
    }

    protected function tearDown(): void
    {
        $this->scenario->remove();
    }

    public function testEveryPushedCommitVerifiesAgainstTheOrchestratorsKey(): void
    {
        $pushed = $this->scenario->push()->push($this->scenario->request());

        self::assertInstanceOf(PushedBranch::class, $pushed);
        self::assertSame($pushed->head, $this->scenario->remoteTip());
        $marks = array_map(static fn (string $line): string => explode(' ', $line)[0], explode("\n", $this->scenario->signatureMarks()));
        self::assertSame(['G', 'G'], $marks);
    }

    public function testThePushedCommitsKeepTheBuildersTreesAndMessages(): void
    {
        $pushed = $this->scenario->push()->push($this->scenario->request());

        self::assertInstanceOf(PushedBranch::class, $pushed);
        self::assertSame($this->scenario->repository->objectAt($this->scenario->head.'^{tree}'), $this->scenario->repository->objectAt($pushed->head.'^{tree}'));
        self::assertSame('Change c.txt', $this->scenario->repository->log('-1', '--format=%s', $pushed->head));
    }

    public function testAPushOfTheSameCommitsGetsTheSameSignedIds(): void
    {
        $first = $this->scenario->signedCommits()->of($this->scenario->gitDirectory(), new AcceptedHandBack(null, $this->scenario->head, 0), 'refs/heads/main');
        $second = $this->scenario->signedCommits()->of($this->scenario->gitDirectory(), new AcceptedHandBack(null, $this->scenario->head, 0), 'refs/heads/main');

        self::assertSame($first, $second);
        self::assertNotSame($this->scenario->head, $first);
    }

    public function testABranchThatConflictsWithMainIsNotPushedAndAsksForAMergeOfMain(): void
    {
        $this->scenario->repository->commitFile('b.txt', "main's own b\n");

        $outcome = $this->scenario->push()->push($this->scenario->request());

        self::assertInstanceOf(MainConflicts::class, $outcome);
        self::assertSame(['b.txt'], $outcome->paths);
        self::assertNull($this->scenario->remoteTip());
        self::assertSame([], $this->scenario->ledger->kinds());
        self::assertSame(0, $this->scenario->changes->calls);
    }

    public function testABranchThatDoesNotConflictWithAMovedMainIsPushed(): void
    {
        $this->scenario->repository->commitFile('d.txt', "main's own d\n");

        self::assertInstanceOf(PushedBranch::class, $this->scenario->push()->push($this->scenario->request()));
    }

    public function testThePushNeverForcesOverABranchThatMovedSinceTheCheckedBase(): void
    {
        $theirs = $this->scenario->pushOtherCommit();

        try {
            (void) $this->scenario->push()->push($this->scenario->request());
            self::fail('The push must refuse a remote branch that is not the checked base.');
        } catch (BranchNotPushed $refused) {
            self::assertStringContainsString('moved', $refused->getMessage());
        }

        self::assertSame($theirs, $this->scenario->remoteTip());
        self::assertSame(0, $this->scenario->changes->calls);
    }

    public function testThePushRefusesABranchThatIsGoneFromTheRemoteWhileTheBaseSaysItWasPushed(): void
    {
        $this->expectException(BranchNotPushed::class);

        (void) $this->scenario->push()->push($this->scenario->request($this->scenario->repository->objectAt('main')));
    }

    public function testTheIntendedIdIsRecordedBeforeThePushAndIsTheOneThePushProduces(): void
    {
        $pushed = $this->scenario->push()->push($this->scenario->request());

        self::assertInstanceOf(PushedBranch::class, $pushed);
        self::assertSame([LedgerEventKind::PushIntended, LedgerEventKind::BranchPushed, LedgerEventKind::ChangeOpened], $this->scenario->ledger->kinds());
        self::assertSame($pushed->head, $this->scenario->ledger->payloadOf(LedgerEventKind::PushIntended)['head']);
        self::assertSame($pushed->change->value, $this->scenario->ledger->payloadOf(LedgerEventKind::ChangeOpened)['change']);
    }

    public function testNothingIsPushedWhenTheIntentCannotBeRecorded(): void
    {
        $this->scenario->ledger->accepts = 0;

        try {
            (void) $this->scenario->push()->push($this->scenario->request());
            self::fail('The push must stop when the ledger refuses the intent.');
        } catch (BranchNotPushed) {
            self::assertNull($this->scenario->remoteTip());
        }
    }

    public function testACrashBetweenThePushAndItsRecordNeitherPushesAgainNorOpensTwoPullRequests(): void
    {
        $this->scenario->ledger->accepts = 1;

        try {
            (void) $this->scenario->push()->push($this->scenario->request());
            self::fail('The ledger refused the record of the push.');
        } catch (BranchNotPushed) {
        }

        $tipAfterTheCrash = $this->scenario->remoteTip();
        $this->scenario->ledger->accepts = PHP_INT_MAX;
        $pushed = $this->scenario->push()->push($this->scenario->request());

        self::assertInstanceOf(PushedBranch::class, $pushed);
        self::assertNotNull($tipAfterTheCrash);
        self::assertSame($tipAfterTheCrash, $this->scenario->remoteTip());
        self::assertSame([LedgerEventKind::PushIntended, LedgerEventKind::BranchPushed, LedgerEventKind::ChangeOpened], $this->scenario->ledger->kinds());
        self::assertSame(1, $this->scenario->changes->opened);
    }

    public function testACrashBetweenThePushAndItsRecordStillOpensThePullRequestWhenMainConflictsSince(): void
    {
        $this->scenario->ledger->accepts = 1;

        try {
            (void) $this->scenario->push()->push($this->scenario->request());
            self::fail('The ledger refused the record of the push.');
        } catch (BranchNotPushed) {
        }

        $this->scenario->repository->commitFile('b.txt', "main's own b\n");
        $this->scenario->ledger->accepts = PHP_INT_MAX;
        $pushed = $this->scenario->push()->push($this->scenario->request());

        self::assertInstanceOf(PushedBranch::class, $pushed);
        self::assertSame($pushed->head, $this->scenario->remoteTip());
        self::assertSame(1, $this->scenario->changes->opened);
    }

    public function testARecordOfAnotherProjectWithTheSameBranchAndHeadDoesNotStandInForThisProjectsPush(): void
    {
        (void) $this->scenario->push()->push($this->scenario->request());
        $other = $this->scenario->otherRemote();

        $pushed = $this->scenario->push()->push($this->scenario->requestOfProject('gadgets', $other));

        self::assertInstanceOf(PushedBranch::class, $pushed);
        self::assertSame($pushed->head, $this->scenario->remoteTip($other));
        self::assertSame('https://github.com/acme/gadgets/pull/2', $pushed->change->value);
    }

    public function testACrashAfterThePushNeitherPushesAgainNorOpensASecondPullRequest(): void
    {
        $this->scenario->changes->during = static fn () => throw BranchNotPushed::because('the process died');

        try {
            (void) $this->scenario->push()->push($this->scenario->request());
            self::fail('The opening was meant to fail.');
        } catch (BranchNotPushed) {
        }

        $tipAfterTheCrash = $this->scenario->remoteTip();
        $this->scenario->changes->during = null;
        $pushed = $this->scenario->push()->push($this->scenario->request());

        self::assertInstanceOf(PushedBranch::class, $pushed);
        self::assertSame($tipAfterTheCrash, $this->scenario->remoteTip());
        self::assertSame([LedgerEventKind::PushIntended, LedgerEventKind::BranchPushed, LedgerEventKind::ChangeOpened], $this->scenario->ledger->kinds());
        self::assertSame(1, $this->scenario->changes->opened);
    }

    public function testACrashAfterOpeningThePullRequestOpensNoSecondOne(): void
    {
        $this->scenario->ledger->accepts = 2;

        try {
            (void) $this->scenario->push()->push($this->scenario->request());
            self::fail('The ledger refused the record of the pull request.');
        } catch (BranchNotPushed) {
        }

        $this->scenario->ledger->accepts = PHP_INT_MAX;
        $pushed = $this->scenario->push()->push($this->scenario->request());

        self::assertInstanceOf(PushedBranch::class, $pushed);
        self::assertSame(1, $this->scenario->changes->opened);
        self::assertSame($pushed->change->value, $this->scenario->ledger->payloadOf(LedgerEventKind::ChangeOpened)['change']);
    }

    public function testAFinishedPushIsNotRepeatedAndAsksTheForgeNothingAgain(): void
    {
        $push = $this->scenario->push();
        $first = $push->push($this->scenario->request());
        $second = $push->push($this->scenario->request());

        self::assertEquals($first, $second);
        self::assertSame(1, $this->scenario->changes->calls);
        self::assertCount(3, $this->scenario->ledger->kinds());
    }

    public function testATextCorrectedForTheSameCommitsReachesTheOpenPullRequest(): void
    {
        $push = $this->scenario->push();
        $corrected = new ChangeText('Corrected title', 'E1: now referenced.');
        $first = $push->push($this->scenario->request());

        $second = $push->push($this->scenario->withText($this->scenario->request(), $corrected));

        self::assertEquals($first, $second);
        self::assertSame(2, $this->scenario->changes->calls);
        self::assertSame(1, $this->scenario->changes->opened);
        self::assertSame($corrected, $this->scenario->changes->text);
        self::assertSame([LedgerEventKind::PushIntended, LedgerEventKind::BranchPushed, LedgerEventKind::ChangeOpened, LedgerEventKind::ChangeOpened], $this->scenario->ledger->kinds());
    }

    public function testAReworkThatHandsBackThePushedHeadWithACorrectedTextUpdatesThePullRequest(): void
    {
        $push = $this->scenario->push();
        $corrected = new ChangeText('Corrected title', 'E1: now referenced.');
        $opened = $push->push($this->scenario->request());
        self::assertInstanceOf(PushedBranch::class, $opened);

        $again = $push->push($this->scenario->withText($this->scenario->request($opened->head, $opened->head), $corrected));

        self::assertEquals($opened, $again);
        self::assertSame($opened->head, $this->scenario->remoteTip());
        self::assertSame(2, $this->scenario->changes->calls);
        self::assertSame($corrected, $this->scenario->changes->text);
    }

    public function testATextThatGoesBackToAnEarlierWordingReachesThePullRequestAgain(): void
    {
        $push = $this->scenario->push();
        $first = $this->scenario->request();
        (void) $push->push($first);
        (void) $push->push($this->scenario->withText($first, new ChangeText('Corrected title', 'E1: now referenced.')));

        (void) $push->push($first);

        self::assertSame(3, $this->scenario->changes->calls);
        self::assertSame(1, $this->scenario->changes->opened);
        self::assertSame($first->text, $this->scenario->changes->text);
    }

    public function testATextWhoseTitleAndBodyOnlyMeetAtAnotherPlaceIsAnotherText(): void
    {
        $push = $this->scenario->push();
        (void) $push->push($this->scenario->withText($this->scenario->request(), new ChangeText('Add the', ' files')));

        (void) $push->push($this->scenario->withText($this->scenario->request(), new ChangeText('Add the ', 'files')));

        self::assertSame(2, $this->scenario->changes->calls);
    }

    public function testACrashAfterATextCorrectionReachedTheForgeSendsItAgainAndOpensNoSecondPullRequest(): void
    {
        $push = $this->scenario->push();
        $corrected = $this->scenario->withText($this->scenario->request(), new ChangeText('Corrected title', 'E1: now referenced.'));
        (void) $push->push($this->scenario->request());
        $this->scenario->ledger->accepts = 3;

        try {
            (void) $push->push($corrected);
            self::fail('The ledger refused the record of the corrected text.');
        } catch (BranchNotPushed) {
        }

        $this->scenario->ledger->accepts = PHP_INT_MAX;
        $pushed = $push->push($corrected);

        self::assertInstanceOf(PushedBranch::class, $pushed);
        self::assertSame(3, $this->scenario->changes->calls);
        self::assertSame(1, $this->scenario->changes->opened);
        self::assertSame($corrected->text, $this->scenario->changes->text);
        self::assertCount(4, $this->scenario->ledger->kinds());
    }

    public function testARecordOfAnOpenedPullRequestThatNamesNoTextDoesNotStandInForTheText(): void
    {
        $request = $this->scenario->request();
        $head = $this->scenario->signedCommits()->of($this->scenario->gitDirectory(), $request->accepted, 'refs/heads/main');
        $this->scenario->ledger->append(new LedgerEvent(LedgerEventKind::ChangeOpened, json_encode(['project' => $request->project, 'branch' => $request->branch, 'head' => $head, 'change' => 'https://github.com/acme/widgets/pull/9'], JSON_THROW_ON_ERROR)));

        $pushed = $this->scenario->push()->push($request);

        self::assertInstanceOf(PushedBranch::class, $pushed);
        self::assertSame(1, $this->scenario->changes->calls);
        self::assertSame('https://github.com/acme/widgets/pull/1', $pushed->change->value);
    }

    public function testTheLedgerKeepsADigestOfThePullRequestTextAndNeverTheText(): void
    {
        (void) $this->scenario->push()->push($this->scenario->request());

        $record = $this->scenario->ledger->payloadOf(LedgerEventKind::ChangeOpened);

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $record['text_digest']);
        self::assertStringNotContainsString('Add the files', implode(' ', $record));
        self::assertStringNotContainsString('Evidence: tests pass.', implode(' ', $record));
    }

    public function testDiscardingTheOutcomeOfAPushIsReported(): void
    {
        $push = $this->scenario->push()->push(...);
        $warnings = [];
        set_error_handler(static function (int $level, string $message) use (&$warnings): bool {
            $warnings[] = $message;

            return true;
        }, E_USER_WARNING);

        try {
            $this->discardTheOutcome($push, $this->scenario->request());
        } finally {
            restore_error_handler();
        }

        self::assertCount(1, $warnings);
        self::assertStringContainsString('a merge-main outcome has to go back to the builder', $warnings[0]);
    }

    public function testASecondPushOfTheSameBranchWhileOneRunsIsRefusedWithoutWaiting(): void
    {
        $push = $this->scenario->push();
        $inner = null;
        $this->scenario->changes->during = function () use ($push, &$inner): void {
            try {
                (void) $push->push($this->scenario->request());
            } catch (BranchNotPushed $busy) {
                $inner = $busy->getMessage();
            }
        };

        self::assertInstanceOf(PushedBranch::class, $push->push($this->scenario->request()));
        self::assertStringContainsString('another push of this branch is running', (string) $inner);
    }

    public function testNothingIsPushedWhenTheSigningKeyCannotSign(): void
    {
        $push = $this->scenario->push($this->scenario->signingKey($this->scenario->key.'.missing'));

        try {
            (void) $push->push($this->scenario->request());
            self::fail('A missing key cannot sign.');
        } catch (BranchNotPushed) {
            self::assertNull($this->scenario->remoteTip());
        }
    }

    public function testAMergeOfMainTheBuilderMadeIsPushedWithItsMainParentUntouched(): void
    {
        $mainTip = $this->scenario->repository->commitFile('d.txt', "main moves on\n");
        $this->scenario->repository->checkout(PushScenario::BRANCH);
        $merge = $this->scenario->repository->merge('main');
        $this->scenario->repository->checkout('main');

        $pushed = $this->scenario->push()->push($this->scenario->request(null, $merge));

        self::assertInstanceOf(PushedBranch::class, $pushed);
        self::assertSame($mainTip, $this->scenario->repository->objectAt($pushed->head.'^2'));
    }

    // Takes a plain Closure because static analysis refuses a discarded call it can see, and the engine still checks this one.
    private function discardTheOutcome(Closure $push, BranchPushRequest $request): void
    {
        $push($request);
    }
}
