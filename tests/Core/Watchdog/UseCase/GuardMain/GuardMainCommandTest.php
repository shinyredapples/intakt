<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Watchdog\UseCase\GuardMain;

use Intakt\Core\Git\Contract\GitCall;
use Intakt\Core\Git\Contract\HardenedGit;
use Intakt\Core\Ledger\Contract\AppendResult;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\LedgerPayloads;
use Intakt\Core\Watchdog\Contract\Classification;
use Intakt\Core\Watchdog\Contract\CommitName;
use Intakt\Core\Watchdog\Contract\MainNotRead;
use Intakt\Core\Watchdog\Contract\MainViolation;
use Intakt\Core\Watchdog\Contract\OwnerCard;
use Intakt\Core\Watchdog\Contract\WatchedMain;
use Intakt\Core\Watchdog\Domain\CheckedMain;
use Intakt\Core\Watchdog\Domain\CommitClassifier;
use Intakt\Core\Watchdog\Domain\HaltClearedPayload;
use Intakt\Core\Watchdog\Domain\HaltPayload;
use Intakt\Core\Watchdog\Domain\OwnerPushNoticePayload;
use Intakt\Core\Watchdog\Infrastructure\ConsoleReport;
use Intakt\Core\Watchdog\Infrastructure\FirstParentChain;
use Intakt\Core\Watchdog\Infrastructure\HaltNotRecorded;
use Intakt\Core\Watchdog\Infrastructure\LedgerHalts;
use Intakt\Core\Watchdog\Infrastructure\LedgerOwnerPushNotices;
use Intakt\Core\Watchdog\Infrastructure\MainHistory;
use Intakt\Core\Watchdog\Infrastructure\NoticeNotRecorded;
use Intakt\Core\Watchdog\Infrastructure\OwnerKey;
use Intakt\Core\Watchdog\UseCase\CheckMain\CheckMain;
use Intakt\Core\Watchdog\UseCase\GuardMain\AHaltNamesItsProject;
use Intakt\Core\Watchdog\UseCase\GuardMain\GuardedMain;
use Intakt\Core\Watchdog\UseCase\GuardMain\GuardMain;
use Intakt\Core\Watchdog\UseCase\GuardMain\GuardMainCommand;
use Intakt\Core\Watchdog\UseCase\GuardMain\GuardMainInput;
use Intakt\Core\Watchdog\UseCase\CheckMain\TrustedKeys;
use Intakt\Tests\Core\Git\TemporaryRepository;
use Intakt\Tests\Core\Watchdog\BeforeTheLock;
use Intakt\Tests\Core\Watchdog\HaltLedger;
use Intakt\Tests\Support\StoredSerializerFactory;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

#[CoversClass(GuardMainCommand::class)]
#[CoversClass(GuardMain::class)]
#[CoversClass(GuardMainInput::class)]
#[CoversClass(GuardedMain::class)]
#[UsesClass(TrustedKeys::class)]
#[CoversClass(AHaltNamesItsProject::class)]
#[CoversClass(ConsoleReport::class)]
#[UsesClass(LedgerOwnerPushNotices::class)]
#[UsesClass(OwnerPushNoticePayload::class)]
#[UsesClass(NoticeNotRecorded::class)]
#[UsesClass(CheckMain::class)]
#[UsesClass(CheckedMain::class)]
#[UsesClass(CommitClassifier::class)]
#[UsesClass(MainHistory::class)]
#[UsesClass(FirstParentChain::class)]
#[UsesClass(OwnerKey::class)]
#[UsesClass(HardenedGit::class)]
#[UsesClass(GitCall::class)]
#[UsesClass(Classification::class)]
#[UsesClass(CommitName::class)]
#[UsesClass(MainNotRead::class)]
#[UsesClass(MainViolation::class)]
#[UsesClass(WatchedMain::class)]
#[UsesClass(LedgerHalts::class)]
#[UsesClass(OwnerCard::class)]
#[UsesClass(HaltNotRecorded::class)]
#[UsesClass(HaltPayload::class)]
#[UsesClass(HaltClearedPayload::class)]
#[UsesClass(LedgerPayloads::class)]
#[UsesClass(AppendResult::class)]
final class GuardMainCommandTest extends TestCase
{
    private const string PROJECT = 'acme/shop';

    private TemporaryRepository $repository;
    private string $keys;
    private HaltLedger $ledger;
    private LedgerHalts $halts;
    private LedgerOwnerPushNotices $notices;
    private BeforeTheLock $store;
    private ?string $path = null;

    public function testASeededUnsignedCommitHaltsTheProjectWithACardNamingTheCommitAndExitsWithOne(): void
    {
        $seen = $this->repository->commitEmpty('Seen');
        $unsigned = $this->repository->commitEmpty('Seeded unsigned commit');

        $tester = $this->whenTheGuardRuns($seen, ['--owner-key' => [$this->publicKey('owner')]]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        $card = $this->onlyStandingCard();
        self::assertSame(sprintf("card %s halts acme/shop: commit %s unsigned, raised 2026-11-21T09:00:00+00:00\n", $card->id->toRfc4122(), $unsigned), $tester->getDisplay());
        self::assertSame([LedgerEventKind::MainHalted], $this->ledger->kinds());
    }

    public function testACommitSignedByAnUnknownKeyHaltsWithAnUnknownKeyCard(): void
    {
        $seen = $this->repository->commitEmpty('Seen');
        $foreign = $this->repository->commitSignedWith($this->privateKey('stranger'), 'Foreign change');

        $tester = $this->whenTheGuardRuns($seen, ['--owner-key' => [$this->publicKey('owner')]]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('commit '.$foreign.' unknown_key', $tester->getDisplay());
        self::assertCount(1, $this->halts->standingOf(self::PROJECT));
    }

    public function testAForcePushHaltsWithAHistoryRewrittenCardOnTheLastSeenHead(): void
    {
        $base = $this->repository->commitEmpty('Base');
        $seen = $this->repository->commitEmpty('Seen, then dropped');
        $this->repository->resetTo($base);
        $this->repository->commitEmpty('Rewritten main');

        $tester = $this->whenTheGuardRuns($seen, []);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('commit '.$seen.' history_rewritten', $tester->getDisplay());
        self::assertCount(1, $this->halts->standingOf(self::PROJECT));
    }

    public function testAnotherRewriteOfTheSameHeadAfterTheOwnerClearedTheFirstRaisesANewCard(): void
    {
        $base = $this->repository->commitEmpty('Base');
        $seen = $this->repository->commitEmpty('Seen, then dropped');
        $this->repository->resetTo($base);
        $this->repository->commitEmpty('First replacement');
        $this->whenTheGuardRuns($seen, []);
        $this->halts->clear($this->onlyStandingCard());
        $this->repository->resetTo($base);
        $this->repository->commitEmpty('Second replacement');

        $tester = $this->whenTheGuardRuns($seen, []);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('commit '.$seen.' history_rewritten', $tester->getDisplay());
        self::assertSame([LedgerEventKind::MainHalted, LedgerEventKind::HaltCleared, LedgerEventKind::MainHalted], $this->ledger->kinds());
    }

    public function testTheSameRewriteSeenAgainAfterTheOwnerClearedItRaisesNoNewCard(): void
    {
        $base = $this->repository->commitEmpty('Base');
        $seen = $this->repository->commitEmpty('Seen, then dropped');
        $this->repository->resetTo($base);
        $this->repository->commitEmpty('Replacement');
        $this->whenTheGuardRuns($seen, []);
        $this->halts->clear($this->onlyStandingCard());

        $tester = $this->whenTheGuardRuns($seen, []);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame([LedgerEventKind::MainHalted, LedgerEventKind::HaltCleared], $this->ledger->kinds());
    }

    public function testARewriteThatMovesToAnotherReplacementWhileTheGuardReadsRaisesACardForTheNewHead(): void
    {
        $base = $this->repository->commitEmpty('Base');
        $seen = $this->repository->commitEmpty('Seen, then dropped');
        $this->repository->resetTo($base);
        $first = $this->repository->commitEmpty('First replacement');
        $this->whenTheGuardRuns($seen, []);
        $this->halts->clear($this->onlyStandingCard());
        $this->repository->resetTo($base);
        $second = $this->repository->commitEmpty('Second replacement');
        $this->repository->resetTo($first);
        $this->gitMovesMainAfterItsFirstRead($second);

        $tester = $this->whenTheGuardRuns($seen, []);

        self::assertSame($second, trim($this->repository->log('-1', '--format=%H')));
        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('commit '.$seen.' history_rewritten', $tester->getDisplay());
        self::assertSame([LedgerEventKind::MainHalted, LedgerEventKind::HaltCleared, LedgerEventKind::MainHalted], $this->ledger->kinds());
    }

    public function testAMainThatMovesOnEveryReadRaisesNoCardAndIsNeverReportedClean(): void
    {
        $seen = $this->repository->commitEmpty('Seen');
        $one = $this->repository->commitEmpty('Seeded unsigned commit');
        $other = $this->repository->commitEmpty('Another unsigned commit');
        $this->gitMovesMainOnEveryRead($other, $one);

        $tester = $this->whenTheGuardRuns($seen, []);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('main moved', $tester->getDisplay());
        self::assertStringNotContainsString('is not halted', $tester->getDisplay());
        self::assertSame([], $this->ledger->kinds());
    }

    public function testAnOwnerSignedCommitPrintsOneNoticeRaisesNoCardAndExitsWithZero(): void
    {
        $seen = $this->repository->commitEmpty('Seen');
        $owner = $this->repository->commitSignedWith($this->privateKey('owner'), 'Owner change');

        $tester = $this->whenTheGuardRuns($seen, ['--owner-key' => [$this->publicKey('owner')]]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame(sprintf("notice acme/shop: commit %s owner_signed, pushed without the gate\nacme/shop is not halted: no owner card stands\n", $owner), $tester->getDisplay());
        self::assertSame([LedgerEventKind::OwnerPushNoticed], $this->ledger->kinds());
    }

    public function testAPeerSignedMergePrintsAnInfoLineRaisesNoCardGivesNoNoticeAndExitsWithZero(): void
    {
        $seen = $this->repository->commitEmpty('Seen');
        $peer = $this->repository->commitSignedWith($this->privateKey('peer'), 'Peer merge');

        $tester = $this->whenTheGuardRuns($seen, ['--owner-key' => [$this->publicKey('owner')], '--peer-key' => [$this->publicKey('peer')]]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame(sprintf("info: peer merge %s peer_signed\nacme/shop is not halted: no owner card stands\n", $peer), $tester->getDisplay());
        self::assertSame([], $this->ledger->kinds());
    }

    public function testAPeerMergeAndARecordedMergeLeaveTheProjectNotHalted(): void
    {
        $seen = $this->repository->commitEmpty('Seen');
        $this->repository->checkoutNewBranch('change');
        $this->repository->commitEmpty('Built change');
        $this->repository->checkout('main');
        $recorded = $this->repository->merge('change');
        $peer = $this->repository->commitSignedWith($this->privateKey('peer'), 'Peer merge');

        $tester = $this->whenTheGuardRuns($seen, ['--recorded-merge' => [$seen.'..'.$recorded], '--peer-key' => [$this->publicKey('peer')]]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame(sprintf("info: peer merge %s peer_signed\nacme/shop is not halted: no owner card stands\n", $peer), $tester->getDisplay());
    }

    public function testAPeerMergeNextToAnUnsignedCommitIsShownAndTheCardStillHalts(): void
    {
        $seen = $this->repository->commitEmpty('Seen');
        $peer = $this->repository->commitSignedWith($this->privateKey('peer'), 'Peer merge');
        $unsigned = $this->repository->commitEmpty('Unsigned change');

        $tester = $this->whenTheGuardRuns($seen, ['--peer-key' => [$this->publicKey('peer')]]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringStartsWith(sprintf("info: peer merge %s peer_signed\ncard ", $peer), $tester->getDisplay());
        self::assertSame($unsigned, $this->onlyStandingCard()->violation?->commit);
    }

    public function testAMergeSignedByThePeerKeyIsAnUnknownKeyCardWhenThePeerKeyIsNotGiven(): void
    {
        $seen = $this->repository->commitEmpty('Seen');
        $peer = $this->repository->commitSignedWith($this->privateKey('peer'), 'Peer merge');

        $tester = $this->whenTheGuardRuns($seen, ['--owner-key' => [$this->publicKey('owner')]]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertSame($peer, $this->onlyStandingCard()->violation?->commit);
    }

    public function testAMalformedPeerKeyExitsInvalidAndRecordsNothing(): void
    {
        $seen = $this->repository->commitEmpty('Seen');

        $tester = $this->whenTheGuardRuns($seen, ['--peer-key' => ['cert-authority secret-looking-value']]);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertStringContainsString('Peer key 1', $tester->getDisplay());
        self::assertStringNotContainsString('secret-looking-value', $tester->getDisplay());
        self::assertSame([], $this->ledger->kinds());
    }

    public function testTheSameOwnerSignedCommitSeenAgainGivesNoSecondNotice(): void
    {
        $seen = $this->repository->commitEmpty('Seen');
        $this->repository->commitSignedWith($this->privateKey('owner'), 'Owner change');
        $this->whenTheGuardRuns($seen, ['--owner-key' => [$this->publicKey('owner')]]);

        $tester = $this->whenTheGuardRuns($seen, ['--owner-key' => [$this->publicKey('owner')]]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame("acme/shop is not halted: no owner card stands\n", $tester->getDisplay());
        self::assertSame([LedgerEventKind::OwnerPushNoticed], $this->ledger->kinds());
    }

    public function testASecondCloneThatReadsTheSameOwnerSignedCommitGivesNoSecondNotice(): void
    {
        $seen = $this->repository->commitEmpty('Seen');
        $this->repository->commitSignedWith($this->privateKey('owner'), 'Owner change');
        $clone = $this->cloneOfTheRepository();
        $this->whenTheGuardRuns($seen, ['--owner-key' => [$this->publicKey('owner')]]);

        $tester = $this->whenTheGuardRunsWith(['project' => self::PROJECT, 'repository' => $clone, 'last-seen-head' => $seen, '--owner-key' => [$this->publicKey('owner')]]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame("acme/shop is not halted: no owner card stands\n", $tester->getDisplay());
        self::assertSame([LedgerEventKind::OwnerPushNoticed], $this->ledger->kinds());
    }

    public function testASecondCloneWhoseGuardFinishesBetweenTheFirstGuardsReadAndItsRecordTakesTheOnlyNotice(): void
    {
        $seen = $this->repository->commitEmpty('Seen');
        $owner = $this->repository->commitSignedWith($this->privateKey('owner'), 'Owner change');
        $clone = $this->cloneOfTheRepository();
        $second = null;
        $this->store->runFirst(function () use ($clone, $seen, &$second): void {
            $second = $this->whenTheGuardRunsWith(['project' => self::PROJECT, 'repository' => $clone, 'last-seen-head' => $seen, '--owner-key' => [$this->publicKey('owner')]]);
        });

        $first = $this->whenTheGuardRuns($seen, ['--owner-key' => [$this->publicKey('owner')]]);

        self::assertInstanceOf(CommandTester::class, $second);
        self::assertStringContainsString('notice acme/shop: commit '.$owner, $second->getDisplay());
        self::assertSame("acme/shop is not halted: no owner card stands\n", $first->getDisplay());
        self::assertSame([LedgerEventKind::OwnerPushNoticed], $this->ledger->kinds());
    }

    public function testTheSameOwnerSignedCommitIsNoticedOnceForEachProject(): void
    {
        $seen = $this->repository->commitEmpty('Seen');
        $owner = $this->repository->commitSignedWith($this->privateKey('owner'), 'Owner change');
        $this->whenTheGuardRuns($seen, ['--owner-key' => [$this->publicKey('owner')]]);

        $tester = $this->whenTheGuardRunsWith(['project' => 'acme/blog', 'repository' => $this->repository->path, 'last-seen-head' => $seen, '--owner-key' => [$this->publicKey('owner')]]);

        self::assertStringContainsString('notice acme/blog: commit '.$owner, $tester->getDisplay());
        self::assertSame([LedgerEventKind::OwnerPushNoticed, LedgerEventKind::OwnerPushNoticed], $this->ledger->kinds());
    }

    public function testAnOwnerPushNextToAViolationIsNoticedAndTheCardStillHalts(): void
    {
        $seen = $this->repository->commitEmpty('Seen');
        $owner = $this->repository->commitSignedWith($this->privateKey('owner'), 'Owner change');
        $unsigned = $this->repository->commitEmpty('Seeded unsigned commit');

        $tester = $this->whenTheGuardRuns($seen, ['--owner-key' => [$this->publicKey('owner')]]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertSame(sprintf("notice acme/shop: commit %s owner_signed, pushed without the gate\ncard %s halts acme/shop: commit %s unsigned, raised 2026-11-21T09:00:00+00:00\n", $owner, $this->onlyStandingCard()->id->toRfc4122(), $unsigned), $tester->getDisplay());
        self::assertSame([LedgerEventKind::MainHalted, LedgerEventKind::OwnerPushNoticed], $this->ledger->kinds());
    }

    public function testANoticeTheLedgerRefusesExitsWithOneAndIsNeverReportedClean(): void
    {
        $seen = $this->repository->commitEmpty('Seen');
        $this->repository->commitSignedWith($this->privateKey('owner'), 'Owner change');
        $this->ledger->refuseAppends();

        $tester = $this->whenTheGuardRuns($seen, ['--owner-key' => [$this->publicKey('owner')]]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('The notice for', $tester->getDisplay());
        self::assertStringNotContainsString('is not halted', $tester->getDisplay());
    }

    public function testANoticeTheLedgerRefusesAfterTakingAnEarlierOneIsPrintedBeforeTheErrorAndNotGivenAgainOnRetry(): void
    {
        $seen = $this->repository->commitEmpty('Seen');
        $first = $this->repository->commitSignedWith($this->privateKey('owner'), 'First owner change');
        $second = $this->repository->commitSignedWith($this->privateKey('owner'), 'Second owner change');
        $this->ledger->refuseAppendsBeyond(1);

        $refused = $this->whenTheGuardRuns($seen, ['--owner-key' => [$this->publicKey('owner')]]);

        self::assertSame(Command::FAILURE, $refused->getStatusCode());
        $display = $refused->getDisplay();
        self::assertStringContainsString('notice acme/shop: commit '.$first, $display);
        self::assertStringNotContainsString('notice acme/shop: commit '.$second, $display);
        self::assertLessThan(strpos($display, 'The notice for'), strpos($display, 'notice acme/shop: commit '.$first));
        self::assertSame([LedgerEventKind::OwnerPushNoticed], $this->ledger->kinds());

        $this->ledger->takeAppendsAgain();
        $retry = $this->whenTheGuardRuns($seen, ['--owner-key' => [$this->publicKey('owner')]]);

        self::assertSame(Command::SUCCESS, $retry->getStatusCode());
        self::assertSame(sprintf("notice acme/shop: commit %s owner_signed, pushed without the gate\nacme/shop is not halted: no owner card stands\n", $second), $retry->getDisplay());
        self::assertSame([LedgerEventKind::OwnerPushNoticed, LedgerEventKind::OwnerPushNoticed], $this->ledger->kinds());
    }

    public function testANoticeLedgerThatCannotBeReadWritesNoNoticeExitsWithOneAndARetryGivesBothNotices(): void
    {
        $seen = $this->repository->commitEmpty('Seen');
        $first = $this->repository->commitSignedWith($this->privateKey('owner'), 'First owner change');
        $second = $this->repository->commitSignedWith($this->privateKey('owner'), 'Second owner change');
        $this->ledger->failReadsOf(LedgerEventKind::OwnerPushNoticed);

        $failed = $this->whenTheApplicationRunsTheGuard(['project' => self::PROJECT, 'repository' => $this->repository->path, 'last-seen-head' => $seen, '--owner-key' => [$this->publicKey('owner')]]);

        self::assertSame(Command::FAILURE, $failed->getStatusCode());
        self::assertStringNotContainsString('notice acme/shop', $failed->getDisplay());
        self::assertSame([], $this->ledger->kinds());

        $this->ledger->readAgain();
        $retry = $this->whenTheGuardRuns($seen, ['--owner-key' => [$this->publicKey('owner')]]);

        self::assertSame(Command::SUCCESS, $retry->getStatusCode());
        self::assertSame(sprintf("notice acme/shop: commit %s owner_signed, pushed without the gate\nnotice acme/shop: commit %s owner_signed, pushed without the gate\nacme/shop is not halted: no owner card stands\n", $first, $second), $retry->getDisplay());
        self::assertSame([LedgerEventKind::OwnerPushNoticed, LedgerEventKind::OwnerPushNoticed], $this->ledger->kinds());
    }

    public function testTheRecordedNoticesAreReadOnceAGuardRunHoweverManyOwnerPushesThereAre(): void
    {
        $seen = $this->repository->commitEmpty('Seen');
        $this->repository->commitSignedWith($this->privateKey('owner'), 'First owner change');
        $this->repository->commitSignedWith($this->privateKey('owner'), 'Second owner change');
        $this->repository->commitSignedWith($this->privateKey('owner'), 'Third owner change');

        $tester = $this->whenTheGuardRuns($seen, ['--owner-key' => [$this->publicKey('owner')]]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame(1, $this->ledger->readsOf(LedgerEventKind::OwnerPushNoticed));
        self::assertCount(3, $this->ledger->kinds());
    }

    public function testAProjectNameThatIsNotValidUtf8IsRefusedWithTwoAndNothingIsRecorded(): void
    {
        $seen = $this->repository->commitEmpty('Seen');
        $this->repository->commitSignedWith($this->privateKey('owner'), 'Owner change');
        $this->repository->commitEmpty('Seeded unsigned commit');

        $tester = $this->whenTheGuardRunsWith(['project' => "demo\xFF", 'repository' => $this->repository->path, 'last-seen-head' => $seen, '--owner-key' => [$this->publicKey('owner')]]);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertStringContainsString('project name given is not valid', $tester->getDisplay());
        self::assertSame([], $this->ledger->kinds());
    }

    public function testAStandingCardKeepsTheProjectHaltedWhileMainIsCleanAgain(): void
    {
        $seen = $this->repository->commitEmpty('Seen');
        $unsigned = $this->repository->commitEmpty('Seeded unsigned commit');
        $this->whenTheGuardRuns($seen, []);

        $tester = $this->whenTheGuardRuns($unsigned, []);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('commit '.$unsigned.' unsigned', $tester->getDisplay());
    }

    public function testAViolationSeenAgainRaisesNoSecondCard(): void
    {
        $seen = $this->repository->commitEmpty('Seen');
        $this->repository->commitEmpty('Seeded unsigned commit');

        $this->whenTheGuardRuns($seen, []);
        $this->whenTheGuardRuns($seen, []);

        self::assertSame([LedgerEventKind::MainHalted], $this->ledger->kinds());
    }

    public function testAViolationTheOwnerClearedRaisesNoNewCard(): void
    {
        $seen = $this->repository->commitEmpty('Seen');
        $this->repository->commitEmpty('Seeded unsigned commit');
        $this->whenTheGuardRuns($seen, []);
        $this->halts->clear($this->onlyStandingCard());

        $tester = $this->whenTheGuardRuns($seen, []);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame([LedgerEventKind::MainHalted, LedgerEventKind::HaltCleared], $this->ledger->kinds());
    }

    public function testAMainThatCannotBeReadRaisesNoCardAndIsNeverReportedClean(): void
    {
        $tester = $this->whenTheGuardRunsWith(['project' => self::PROJECT, 'repository' => $this->repository->path.'/missing', 'last-seen-head' => str_repeat('a', 40)]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Main was not read', $tester->getDisplay());
        self::assertStringNotContainsString('is not halted', $tester->getDisplay());
        self::assertSame([], $this->ledger->kinds());
    }

    public function testAHaltTheLedgerRefusesExitsWithOneAndIsNeverReportedClean(): void
    {
        $seen = $this->repository->commitEmpty('Seen');
        $this->repository->commitEmpty('Seeded unsigned commit');
        $this->ledger->refuseAppends();

        $tester = $this->whenTheGuardRuns($seen, []);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('was not recorded', $tester->getDisplay());
        self::assertStringNotContainsString('is not halted', $tester->getDisplay());
    }

    public function testAnEmptyProjectIsRefusedWithTwoBeforeMainIsRead(): void
    {
        $seen = $this->repository->commitEmpty('Seen');
        $this->repository->commitEmpty('Seeded unsigned commit');

        $tester = $this->whenTheGuardRunsWith(['project' => ' ', 'repository' => $this->repository->path, 'last-seen-head' => $seen]);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertStringContainsString('A halt names its project', $tester->getDisplay());
        self::assertSame([], $this->ledger->kinds());
    }

    public function testAProjectNameWithALineBreakIsPrintedOnOneEscapedLine(): void
    {
        $seen = $this->repository->commitEmpty('Seen');

        $tester = $this->whenTheGuardRunsWith(['project' => "acme/shop\nforged", 'repository' => $this->repository->path, 'last-seen-head' => $seen]);

        self::assertSame("acme/shop\\nforged is not halted: no owner card stands\n", $tester->getDisplay());
    }

    public function testAProjectNameWithFormatterTagsIsPrintedAsTypedInTheNotice(): void
    {
        $seen = $this->repository->commitEmpty('Seen');
        $owner = $this->repository->commitSignedWith($this->privateKey('owner'), 'Owner change');

        $tester = $this->whenTheGuardRunsWith(['project' => '<info>demo</info>', 'repository' => $this->repository->path, 'last-seen-head' => $seen, '--owner-key' => [$this->publicKey('owner')]]);

        self::assertStringContainsString(sprintf('notice <info>demo</info>: commit %s owner_signed', $owner), $tester->getDisplay());
    }

    #[Override]
    protected function setUp(): void
    {
        $this->repository = new TemporaryRepository();
        $this->keys = sys_get_temp_dir().'/intakt-watchdog-keys-'.bin2hex(random_bytes(6));
        mkdir($this->keys);
        $this->ledger = new HaltLedger();
        $payloads = new LedgerPayloads(StoredSerializerFactory::create());
        $this->halts = new LedgerHalts($this->ledger, $payloads);
        $this->notices = new LedgerOwnerPushNotices($this->ledger, $payloads);
        $this->store = new BeforeTheLock(new InMemoryStore());
    }

    #[Override]
    protected function tearDown(): void
    {
        if (null !== $this->path) {
            putenv('PATH='.$this->path);
        }
        $this->repository->remove();
        new Filesystem()->remove($this->keys);
    }

    // Through the application, because a ledger that throws on a read is caught and reported there, as on a real install.
    /**
     * @param array<string, string|list<string>> $input
     */
    private function whenTheApplicationRunsTheGuard(array $input): ApplicationTester
    {
        $guard = new GuardMain(new CheckMain(new HardenedGit(), new Filesystem()), $this->halts, $this->notices, new LockFactory($this->store));
        $application = new Application();
        $application->setAutoExit(false);
        $application->addCommand(new GuardMainCommand($guard));
        $tester = new ApplicationTester($application);
        $tester->run(['command' => GuardMainCommand::NAME, ...$input]);

        return $tester;
    }

    // Kept under the keys folder, because tearDown removes that folder and so the clone with it.
    private function cloneOfTheRepository(): string
    {
        $clone = $this->keys.'/clone';
        new Process(['git', 'clone', '--quiet', $this->repository->path, $clone])->mustRun();

        return $clone;
    }

    /**
     * @param array<string, string|list<string>> $input
     */
    private function whenTheGuardRunsWith(array $input): CommandTester
    {
        $guard = new GuardMain(new CheckMain(new HardenedGit(), new Filesystem()), $this->halts, $this->notices, new LockFactory($this->store));
        $application = new Application();
        $application->addCommand(new GuardMainCommand($guard));
        $tester = new CommandTester($application->find(GuardMainCommand::NAME));
        $tester->execute($input);

        return $tester;
    }

    // A git on the PATH that moves main right after the watchdog reads it, because only that forces a push between two of the guard's reads.
    private function gitMovesMainAfterItsFirstRead(string $commit): void
    {
        $this->gitMovesMainAfterARead(sprintf('if [ ! -f %1$s/moved ]; then %2$s update-ref refs/heads/main %3$s; touch %1$s/moved; fi', escapeshellarg($this->keys), $this->realGit(), $commit));
    }

    private function gitMovesMainOnEveryRead(string $one, string $other): void
    {
        $flip = escapeshellarg($this->keys.'/flipped');
        $this->gitMovesMainAfterARead(sprintf('if [ -f %1$s ]; then %2$s update-ref refs/heads/main %3$s; rm %1$s; else %2$s update-ref refs/heads/main %4$s; touch %1$s; fi', $flip, $this->realGit(), $one, $other));
    }

    private function realGit(): string
    {
        return escapeshellarg(new ExecutableFinder()->find('git') ?? self::fail('git is not installed'));
    }

    private function gitMovesMainAfterARead(string $move): void
    {
        $bin = $this->keys.'/bin';
        mkdir($bin);
        file_put_contents($bin.'/git', sprintf("#!/bin/sh\ncase \" \$* \" in\n*' show-ref --verify --hash refs/heads/main '*) %1\$s \"\$@\" || exit \$?; %2\$s; exit 0;;\nesac\nexec %1\$s \"\$@\"\n", $this->realGit(), $move));
        chmod($bin.'/git', 0o755);
        $this->path = (string) getenv('PATH');
        putenv('PATH='.$bin.PATH_SEPARATOR.$this->path);
    }

    private function privateKey(string $name): string
    {
        $file = $this->keys.'/'.$name;
        if (!is_file($file)) {
            new Process(['ssh-keygen', '-q', '-t', 'ed25519', '-N', '', '-C', $name, '-f', $file])->mustRun();
        }

        return $file;
    }

    private function publicKey(string $name): string
    {
        $parts = explode(' ', trim((string) file_get_contents($this->privateKey($name).'.pub')));

        return $parts[0].' '.$parts[1];
    }

    /**
     * @param array<string, list<string>> $options
     */
    private function whenTheGuardRuns(string $seen, array $options): CommandTester
    {
        return $this->whenTheGuardRunsWith(['project' => self::PROJECT, 'repository' => $this->repository->path, 'last-seen-head' => $seen, ...$options]);
    }

    private function onlyStandingCard(): OwnerCard
    {
        $standing = $this->halts->standing();
        self::assertCount(1, $standing);

        return $standing[0];
    }
}
