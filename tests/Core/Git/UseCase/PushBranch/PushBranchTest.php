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
use Intakt\Plugin\Forge\Contract\ForgeWriteFailed;
use Intakt\Plugin\Forge\Contract\OpenedChange;
use Intakt\Plugin\Forge\Contract\Opening;
use Intakt\Plugin\Forge\Contract\ProposedChange;
use Intakt\Tests\Core\Git\TemporaryRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\Exception\ExceptionInterface;
use Symfony\Component\Lock\Exception\LockStorageException;
use Symfony\Component\Lock\Key;
use Symfony\Component\Lock\PersistingStoreInterface;
use Symfony\Component\Lock\Store\InMemoryStore;

#[CoversClass(PushBranch::class)]
#[CoversClass(PushRecords::class)]
#[CoversClass(BranchGit::class)]
#[UsesClass(GuardedRun::class)]
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
#[UsesClass(OpenedChange::class)]
#[UsesClass(ProposedChange::class)]
#[UsesClass(ForgeWriteFailed::class)]
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

    #[DataProvider('fileNamesGitQuotesInItsLines')]
    public function testAConflictNamesTheFileAsItIsCalled(string $name): void
    {
        $head = $this->scenario->commitOnTheBranch($name, "the builder's\n");
        $this->scenario->repository->commitFile($name, "main's own\n");

        $outcome = $this->scenario->push()->push($this->scenario->request(null, $head));

        self::assertInstanceOf(MainConflicts::class, $outcome);
        self::assertSame([$name], $outcome->paths);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function fileNamesGitQuotesInItsLines(): iterable
    {
        yield 'a letter outside ASCII' => ["caf\u{e9}.txt"];
        yield 'a double quote' => ['say "hi".txt'];
        yield 'a line break' => ["two\nlines.txt"];
    }

    public function testEveryConflictingFileIsNamedOnce(): void
    {
        $this->scenario->repository->commitFile('b.txt', "main's own b\n");
        $this->scenario->repository->commitFile('c.txt', "main's own c\n");

        $outcome = $this->scenario->push()->push($this->scenario->request());

        self::assertInstanceOf(MainConflicts::class, $outcome);
        self::assertSame(['b.txt', 'c.txt'], $outcome->paths);
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

    #[DataProvider('rewritesOfTheRemote')]
    public function testARemoteTheRepositorysConfigRewritesToAnotherRepositoryIsNeitherReadNorRecordedNorPushed(string $kind, string $part): void
    {
        $other = $this->scenario->otherRemote();
        $remote = $this->scenario->remote;
        $prefix = match ($part) {
            'whole' => $remote,
            'prefix' => substr($remote, 0, -strlen('remote.git')),
            default => '',
        };
        $this->scenario->rewriteInTheRepository($kind, $prefix, $other);

        try {
            (void) $this->scenario->push()->push($this->scenario->request());
            self::fail('A rewritten remote must be refused.');
        } catch (BranchNotPushed $refused) {
            self::assertStringContainsString('rewrites the remote\'s address', $refused->getMessage());
        }

        self::assertNull($this->scenario->remoteTip($other));
        self::assertNull($this->scenario->remoteTip());
        self::assertSame([], $this->scenario->ledger->kinds());
        self::assertSame(0, $this->scenario->changes->calls);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function rewritesOfTheRemote(): iterable
    {
        yield 'insteadOf the whole remote' => ['insteadOf', 'whole'];
        yield 'pushInsteadOf the whole remote' => ['pushInsteadOf', 'whole'];
        yield 'insteadOf a prefix of the remote' => ['insteadOf', 'prefix'];
        yield 'an empty insteadOf' => ['insteadOf', 'empty'];
    }

    public function testARewriteOfAnotherAddressDoesNotStopThePush(): void
    {
        $this->scenario->rewriteInTheRepository('insteadOf', 'https://example.com/elsewhere/', $this->scenario->otherRemote());

        self::assertInstanceOf(PushedBranch::class, $this->scenario->push()->push($this->scenario->request()));
    }

    public function testTheForeignChangeCheckRunsBeforeAnythingIsRecordedOrPushed(): void
    {
        $atTheCheck = null;
        $this->scenario->changes->checking = function () use (&$atTheCheck): void {
            $atTheCheck = [$this->scenario->ledger->kinds(), $this->scenario->remoteTip()];
        };

        (void) $this->scenario->push()->push($this->scenario->request());

        self::assertSame([[], null], $atTheCheck);
    }

    public function testABranchWithAForeignOpenPullRequestIsNeitherRecordedNorPushed(): void
    {
        $this->scenario->changes->checking = static function (): never {
            throw new ForgeWriteFailed('The branch has an open pull request that another account opened.');
        };

        try {
            (void) $this->scenario->push()->push($this->scenario->request());
            self::fail('A branch with a foreign open pull request must not be pushed.');
        } catch (ForgeWriteFailed) {
        }

        self::assertSame([], $this->scenario->ledger->kinds());
        self::assertNull($this->scenario->remoteTip());
        self::assertSame(0, $this->scenario->changes->calls);
    }

    public function testTheForeignChangeCheckNamesTheProjectBranchAndMain(): void
    {
        $checked = null;
        $this->scenario->changes->checking = static function (ProposedChange $change) use (&$checked): void {
            $checked = [$change->project, $change->branch, $change->base];
        };

        (void) $this->scenario->push()->push($this->scenario->request());

        self::assertSame(['widgets', PushScenario::BRANCH, 'main'], $checked);
    }

    public function testTheIntendedIdIsRecordedBeforeThePushAndIsTheOneThePushProduces(): void
    {
        $pushed = $this->scenario->push()->push($this->scenario->request());

        self::assertInstanceOf(PushedBranch::class, $pushed);
        self::assertSame([LedgerEventKind::PushIntended, LedgerEventKind::BranchPushed, LedgerEventKind::ChangeIntended, LedgerEventKind::ChangeOpened], $this->scenario->ledger->kinds());
        self::assertSame($pushed->head, $this->scenario->ledger->payloadOf(LedgerEventKind::PushIntended)['head']);
        self::assertSame($pushed->change->value, $this->scenario->ledger->payloadOf(LedgerEventKind::ChangeOpened)['change']);
    }

    public function testTheIntentToWriteThePullRequestIsRecordedBeforeTheForgeIsAsked(): void
    {
        $kindsAtTheForge = null;
        $this->scenario->changes->during = function () use (&$kindsAtTheForge): void {
            $kindsAtTheForge = $this->scenario->ledger->kinds();
        };

        (void) $this->scenario->push()->push($this->scenario->request());

        self::assertSame([LedgerEventKind::PushIntended, LedgerEventKind::BranchPushed, LedgerEventKind::ChangeIntended], $kindsAtTheForge);
    }

    public function testTheIntentToWriteThePullRequestNamesTheHeadAndNoText(): void
    {
        $pushed = $this->scenario->push()->push($this->scenario->request());

        self::assertInstanceOf(PushedBranch::class, $pushed);
        self::assertSame(['project' => 'widgets', 'branch' => PushScenario::BRANCH, 'head' => $pushed->head], $this->scenario->ledger->payloadOf(LedgerEventKind::ChangeIntended));
    }

    public function testTheForgeIsNotAskedWhenTheIntentToWriteThePullRequestCannotBeRecorded(): void
    {
        $this->scenario->ledger->accepts = 2;

        try {
            (void) $this->scenario->push()->push($this->scenario->request());
            self::fail('The push must stop when the ledger refuses the intent to write the pull request.');
        } catch (BranchNotPushed $refused) {
            self::assertStringContainsString('change_intended', $refused->getMessage());
        }

        self::assertSame(0, $this->scenario->changes->calls);
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
        self::assertSame([LedgerEventKind::PushIntended, LedgerEventKind::BranchPushed, LedgerEventKind::ChangeIntended, LedgerEventKind::ChangeOpened], $this->scenario->ledger->kinds());
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
        self::assertSame([LedgerEventKind::PushIntended, LedgerEventKind::BranchPushed, LedgerEventKind::ChangeIntended, LedgerEventKind::ChangeIntended, LedgerEventKind::ChangeOpened], $this->scenario->ledger->kinds());
        self::assertSame(1, $this->scenario->changes->opened);
    }

    public function testACrashAfterOpeningThePullRequestOpensNoSecondOne(): void
    {
        $this->scenario->ledger->accepts = 3;

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
        self::assertCount(4, $this->scenario->ledger->kinds());
    }

    public function testARetryOfAHeadTheBranchMovedPastWithACorrectedTextLeavesThePullRequestAlone(): void
    {
        $push = $this->scenario->push();
        $first = $push->push($this->scenario->request());
        self::assertInstanceOf(PushedBranch::class, $first);
        $this->pushASecondBuildOnTopOf($push, $first->head);

        $this->assertARetryOfTheFirstHeadIsRefused($push, new ChangeText('Late correction of the first build', 'Evidence of the first build.'));

        self::assertSame('Second build', $this->scenario->changes->text?->title);
    }

    public function testARetryOfAHeadTheBranchMovedPastIsNotReportedAsPushed(): void
    {
        $push = $this->scenario->push();
        $first = $push->push($this->scenario->request());
        self::assertInstanceOf(PushedBranch::class, $first);
        $second = $this->pushASecondBuildOnTopOf($push, $first->head);

        $this->assertARetryOfTheFirstHeadIsRefused($push, $this->scenario->request()->text);

        self::assertNotSame($first->head, $second->head);
        self::assertSame($second->head, $this->scenario->remoteTip());
    }

    public function testARetryOfAHeadWhosePullRequestWriteWasNeverRecordedIsRefusedOnceTheBranchMovedPast(): void
    {
        $push = $this->scenario->push();
        $this->scenario->changes->during = static fn () => throw BranchNotPushed::because('the forge is down');

        try {
            (void) $push->push($this->scenario->request());
            self::fail('The opening was meant to fail.');
        } catch (BranchNotPushed) {
        }

        $this->scenario->changes->during = null;
        self::assertSame([LedgerEventKind::PushIntended, LedgerEventKind::BranchPushed, LedgerEventKind::ChangeIntended], $this->scenario->ledger->kinds());
        $this->pushASecondBuildOnTopOf($push, (string) $this->scenario->remoteTip());

        $this->assertARetryOfTheFirstHeadIsRefused($push, $this->scenario->request()->text);

        self::assertSame('Second build', $this->scenario->changes->text?->title);
    }

    public function testANewBranchIsPushedAlthoughAnotherRefEndsInItsName(): void
    {
        $this->scenario->pushDecoyRef('main');

        $pushed = $this->scenario->push()->push($this->scenario->request());

        self::assertInstanceOf(PushedBranch::class, $pushed);
        self::assertSame($pushed->head, $this->scenario->remoteTip());
    }

    public function testARetryOfAHeadTheBranchMovedPastIsRefusedAlthoughAnotherRefEndingInItsNameHoldsThatHead(): void
    {
        $push = $this->scenario->push();
        $first = $push->push($this->scenario->request());
        self::assertInstanceOf(PushedBranch::class, $first);
        $this->pushASecondBuildOnTopOf($push, $first->head);
        $this->scenario->pushDecoyRef($first->head);

        $this->assertARetryOfTheFirstHeadIsRefused($push, new ChangeText('Late title', 'Late text.'));

        self::assertSame('Second build', $this->scenario->changes->text?->title);
    }

    public function testARetryOfARecordedHeadIsRefusedOnceAnotherWriterMovedTheBranch(): void
    {
        $push = $this->scenario->push();
        (void) $push->push($this->scenario->request());
        $theirs = $this->scenario->pushOtherCommit();

        $this->assertARetryOfTheFirstHeadIsRefused($push, new ChangeText('Corrected title', 'E1: now referenced.'));

        self::assertSame($theirs, $this->scenario->remoteTip());
        self::assertSame('Add the files', $this->scenario->changes->text?->title);
    }

    public function testARetryOfARecordedHeadIsRefusedOnceTheBranchIsGoneFromTheRemote(): void
    {
        $push = $this->scenario->push();
        (void) $push->push($this->scenario->request());
        $this->scenario->deleteRemoteBranch();

        $this->assertARetryOfTheFirstHeadIsRefused($push, new ChangeText('Corrected title', 'E1: now referenced.'));

        self::assertNull($this->scenario->remoteTip());
    }

    public function testAnIntentOfAnotherProjectDoesNotSendThisProjectsTextAgain(): void
    {
        $push = $this->scenario->push();
        $pushed = $push->push($this->scenario->request());
        self::assertInstanceOf(PushedBranch::class, $pushed);
        $this->scenario->ledger->append(new LedgerEvent(LedgerEventKind::ChangeIntended, json_encode(['project' => 'gadgets', 'branch' => PushScenario::BRANCH, 'head' => $pushed->head], JSON_THROW_ON_ERROR)));

        (void) $push->push($this->scenario->request());

        self::assertSame(1, $this->scenario->changes->calls);
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
        self::assertSame([LedgerEventKind::PushIntended, LedgerEventKind::BranchPushed, LedgerEventKind::ChangeIntended, LedgerEventKind::ChangeOpened, LedgerEventKind::ChangeIntended, LedgerEventKind::ChangeOpened], $this->scenario->ledger->kinds());
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
        $this->scenario->ledger->accepts = 5;

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
        self::assertCount(7, $this->scenario->ledger->kinds());
    }

    public function testAnEarlierTextPushedAfterAnUnrecordedCorrectionReachesTheForge(): void
    {
        $push = $this->scenario->push();
        $original = $this->scenario->request();
        (void) $push->push($original);
        $this->pushWhoseTextTheLedgerNeverRecords($push, $this->scenario->withText($original, new ChangeText('Corrected title', 'E1: now referenced.')));

        $pushed = $push->push($original);

        self::assertInstanceOf(PushedBranch::class, $pushed);
        self::assertSame($original->text, $this->scenario->changes->text);
        self::assertSame(1, $this->scenario->changes->opened);
    }

    public function testAnEarlierTextStillReachesTheForgeWhenItsFirstRetryMetAForgeThatWasDown(): void
    {
        $push = $this->scenario->push();
        $original = $this->scenario->request();
        (void) $push->push($original);
        $this->pushWhoseTextTheLedgerNeverRecords($push, $this->scenario->withText($original, new ChangeText('Corrected title', 'E1: now referenced.')));
        $this->scenario->changes->during = static fn () => throw BranchNotPushed::because('the forge is down');

        try {
            (void) $push->push($original);
        } catch (BranchNotPushed) {
        }

        $this->scenario->changes->during = null;
        $pushed = $push->push($original);

        self::assertInstanceOf(PushedBranch::class, $pushed);
        self::assertSame($original->text, $this->scenario->changes->text);
        self::assertSame(1, $this->scenario->changes->opened);
    }

    public function testARecordedTextNoLongerStandsInOnceALaterWriteWasIntended(): void
    {
        $records = new PushRecords($this->scenario->ledger);
        $request = $this->scenario->request();
        $head = str_repeat('b', 40);
        $records->intendChange($request, $head);
        $records->open($request, $head, new OpenedChange(new ChangeId('https://github.com/acme/widgets/pull/1'), Opening::Created));

        $records->intendChange($request, $head);

        self::assertNull($records->openedChange($request, $head));
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

    public function testARecordOfAnOpenedPullRequestThatDoesNotSayHowTheForgeGotThereDoesNotStandInForTheSameText(): void
    {
        $request = $this->scenario->request();
        $head = $this->scenario->signedCommits()->of($this->scenario->gitDirectory(), $request->accepted, 'refs/heads/main');
        $digest = hash('sha256', strlen($request->text->title).':'.$request->text->title.$request->text->body);
        $this->scenario->ledger->append(new LedgerEvent(LedgerEventKind::ChangeOpened, json_encode(['project' => $request->project, 'branch' => $request->branch, 'head' => $head, 'change' => 'https://github.com/acme/widgets/pull/1', 'text_digest' => $digest], JSON_THROW_ON_ERROR)));

        $pushed = $this->scenario->push()->push($request);

        self::assertInstanceOf(PushedBranch::class, $pushed);
        self::assertSame(1, $this->scenario->changes->calls);
        self::assertSame([null, 'created'], array_map(static fn (array $record): mixed => $record['opening'] ?? null, $this->scenario->ledger->payloadsOf(LedgerEventKind::ChangeOpened)));
    }

    public function testTheRecordOfAPullRequestTheForgeCreatedSaysItWasCreated(): void
    {
        (void) $this->scenario->push()->push($this->scenario->request());

        self::assertSame('created', $this->scenario->ledger->payloadOf(LedgerEventKind::ChangeOpened)['opening']);
    }

    public function testTheRecordOfAPullRequestTheForgeOnlyUpdatedSaysItWasUpdated(): void
    {
        $push = $this->scenario->push();
        (void) $push->push($this->scenario->request());

        (void) $push->push($this->scenario->withText($this->scenario->request(), new ChangeText('Corrected title', 'E1: now referenced.')));

        self::assertSame(['created', 'updated'], array_column($this->scenario->ledger->payloadsOf(LedgerEventKind::ChangeOpened), 'opening'));
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

    public function testALockStoreThatFailsToSaveIsReportedAsNotPushedAndNothingIsPushedOrRecorded(): void
    {
        $store = self::createStub(PersistingStoreInterface::class);
        $store->method('save')->willThrowException(new LockStorageException('the lock database is down'));

        try {
            (void) $this->scenario->pushWithLockStore($store)->push($this->scenario->request());
            self::fail('A lock store that cannot save must stop the push.');
        } catch (BranchNotPushed $failed) {
            self::assertStringStartsWith('The branch was not pushed: the lock store failed: ', $failed->getMessage());
            self::assertInstanceOf(ExceptionInterface::class, $failed->getPrevious());
            self::assertNull($this->scenario->remoteTip());
            self::assertSame([], $this->scenario->ledger->kinds());
        }
    }

    public function testALockStoreThatFailsToReleaseAfterThePushIsReportedAsNotPushed(): void
    {
        $store = self::createStub(PersistingStoreInterface::class);
        $store->method('delete')->willThrowException(new LockStorageException('the lock database is down'));

        try {
            (void) $this->scenario->pushWithLockStore($store)->push($this->scenario->request());
            self::fail('A lock store that cannot release must be reported.');
        } catch (BranchNotPushed $failed) {
            self::assertStringStartsWith('The branch was not pushed: the lock store failed: ', $failed->getMessage());
            self::assertInstanceOf(ExceptionInterface::class, $failed->getPrevious());
        }
    }

    public function testALockStoreThatFailsToReleaseAndStillHoldsTheLockIsReportedAsNotPushed(): void
    {
        $store = self::createStub(PersistingStoreInterface::class);
        $store->method('delete')->willThrowException(new LockStorageException('the lock database is down'));
        $store->method('exists')->willReturn(true);

        $this->assertReportedAsALockStoreFailure($store);
    }

    public function testALockStoreThatFailsToReleaseAndToTellWhetherTheLockIsHeldIsReportedAsNotPushed(): void
    {
        $store = self::createStub(PersistingStoreInterface::class);
        $store->method('delete')->willThrowException(new LockStorageException('the lock database is down'));
        $store->method('exists')->willThrowException(new LockStorageException('the lock database is down'));

        $this->assertReportedAsALockStoreFailure($store);
    }

    public function testALockStoreWhoseReleaseFailsOnceReleasesTheBranchSoTheNextCallReadsThePush(): void
    {
        $locks = new InMemoryStore();
        $failures = 1;
        $store = self::createStub(PersistingStoreInterface::class);
        $store->method('save')->willReturnCallback($locks->save(...));
        $store->method('exists')->willReturnCallback($locks->exists(...));
        $store->method('delete')->willReturnCallback(static function (Key $key) use ($locks, &$failures): void {
            if ($failures-- > 0) {
                throw new LockStorageException('a short outage of the lock database');
            }

            $locks->delete($key);
        });
        $push = $this->scenario->pushWithLockStore($store);

        $first = $push->push($this->scenario->request());
        $again = $push->push($this->scenario->request());

        self::assertInstanceOf(PushedBranch::class, $first);
        self::assertInstanceOf(PushedBranch::class, $again);
        self::assertSame($first->head, $again->head);
        self::assertSame(1, $this->scenario->changes->calls);
    }

    public function testAFailureDuringThePushIsReportedInsteadOfAFailedLockRelease(): void
    {
        $store = self::createStub(PersistingStoreInterface::class);
        $store->method('delete')->willThrowException(new LockStorageException('the lock database is down'));
        $push = $this->scenario->pushWithLockStore($store, $this->scenario->signingKey($this->scenario->key.'.missing'));

        try {
            (void) $push->push($this->scenario->request());
            self::fail('A missing key cannot sign.');
        } catch (BranchNotPushed $failed) {
            self::assertStringNotContainsString('the lock store failed', $failed->getMessage());
            self::assertNull($this->scenario->remoteTip());
        }
    }

    public function testAPushWhoseLockReleaseFailedIsReportedPushedByTheNextCallWithoutPushingAgain(): void
    {
        $store = self::createStub(PersistingStoreInterface::class);
        $store->method('delete')->willThrowException(new LockStorageException('the lock database is down'));

        try {
            (void) $this->scenario->pushWithLockStore($store)->push($this->scenario->request());
        } catch (BranchNotPushed) {
        }

        $tipAfterTheFailure = $this->scenario->remoteTip();
        $pushed = $this->scenario->push()->push($this->scenario->request());

        self::assertInstanceOf(PushedBranch::class, $pushed);
        self::assertSame($tipAfterTheFailure, $pushed->head);
        self::assertSame([LedgerEventKind::PushIntended, LedgerEventKind::BranchPushed, LedgerEventKind::ChangeIntended, LedgerEventKind::ChangeOpened], $this->scenario->ledger->kinds());
        self::assertSame(1, $this->scenario->changes->calls);
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

    public function testAPushKilledByASignalIsReportedAsNotPushedAndRecordsNoPush(): void
    {
        $programs = new ProgramOnPath();
        $programs->killOn('git', 'push');

        try {
            (void) $this->scenario->push()->push($this->scenario->request());
            self::fail('A killed push must not count as pushed.');
        } catch (BranchNotPushed $stopped) {
            self::assertSame('The branch was not pushed: git push was killed by signal 15', $stopped->getMessage());
            self::assertNull($stopped->getPrevious());
            self::assertSame([LedgerEventKind::PushIntended], $this->scenario->ledger->kinds());
        } finally {
            $programs->remove();
        }
    }

    public function testAPushThatWasKilledIsPushedByTheNextCall(): void
    {
        $programs = new ProgramOnPath();
        $programs->killOn('git', 'push');

        try {
            (void) $this->scenario->push()->push($this->scenario->request());
            self::fail('A killed push must not count as pushed.');
        } catch (BranchNotPushed) {
            $programs->remove();
        }

        $pushed = $this->scenario->push()->push($this->scenario->request());

        self::assertInstanceOf(PushedBranch::class, $pushed);
        self::assertSame($pushed->head, $this->scenario->remoteTip());
        self::assertSame([LedgerEventKind::PushIntended, LedgerEventKind::BranchPushed, LedgerEventKind::ChangeIntended, LedgerEventKind::ChangeOpened], $this->scenario->ledger->kinds());
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

    // On top of the first build's signed head, because a retry of the first head then meets a branch that moved past it.
    private function pushASecondBuildOnTopOf(PushBranch $push, string $firstSignedHead): PushedBranch
    {
        $next = $this->scenario->commitOnTheBranch('e.txt', "four\n");
        $second = $push->push($this->scenario->withText($this->scenario->request($firstSignedHead, $next), new ChangeText('Second build', 'Evidence of the second build.')));

        self::assertInstanceOf(PushedBranch::class, $second);
        self::assertSame($second->head, $this->scenario->remoteTip());

        return $second;
    }

    // Compares the forge and the ledger with what they held before the retry, because a refusal that comes after either was written to is too late.
    private function assertARetryOfTheFirstHeadIsRefused(PushBranch $push, ChangeText $text): void
    {
        $textBefore = $this->scenario->changes->text;
        $callsBefore = $this->scenario->changes->calls;
        $recordsBefore = $this->scenario->ledger->kinds();

        try {
            (void) $push->push($this->scenario->withText($this->scenario->request(), $text));
            self::fail('A head the remote branch moved past must be refused.');
        } catch (BranchNotPushed $refused) {
            self::assertStringContainsString('moved since this head was pushed', $refused->getMessage());
        }

        self::assertSame($textBefore, $this->scenario->changes->text);
        self::assertSame($callsBefore, $this->scenario->changes->calls);
        self::assertSame($recordsBefore, $this->scenario->ledger->kinds());
    }

    // Ends the push right after the forge took the text, because that is the write whose outcome the ledger never learns.
    private function pushWhoseTextTheLedgerNeverRecords(PushBranch $push, BranchPushRequest $request): void
    {
        $this->scenario->changes->during = function (): void {
            $this->scenario->ledger->accepts = count($this->scenario->ledger->kinds());
        };

        try {
            (void) $push->push($request);
            self::fail('The ledger refused the record of the text.');
        } catch (BranchNotPushed) {
        }

        $this->scenario->changes->during = null;
        $this->scenario->ledger->accepts = PHP_INT_MAX;
        self::assertSame($request->text, $this->scenario->changes->text);
    }

    // Takes a plain Closure because static analysis refuses a discarded call it can see, and the engine still checks this one.
    private function discardTheOutcome(Closure $push, BranchPushRequest $request): void
    {
        $push($request);
    }

    // Caught at the top, because an exception Symfony's lock throws on destruct would replace BranchNotPushed and keep it only as a previous one.
    private function assertReportedAsALockStoreFailure(PersistingStoreInterface $store): void
    {
        try {
            (void) $this->scenario->pushWithLockStore($store)->push($this->scenario->request());
            self::fail('A lock store that cannot release must be reported.');
        } catch (ExceptionInterface $escaped) {
            self::fail('The lock store failure left the contract as '.$escaped::class);
        } catch (BranchNotPushed $failed) {
            self::assertStringStartsWith('The branch was not pushed: the lock store failed: ', $failed->getMessage());
        }
    }
}
