<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\HandBack\UseCase\CheckHandBack;

use Intakt\Core\Git\Contract\GitCall;
use Intakt\Core\Git\Contract\HardenedGit;
use Intakt\Core\HandBack\Contract\AcceptedHandBack;
use Intakt\Core\HandBack\Contract\HandBack;
use Intakt\Core\HandBack\Contract\HandBackNotChecked;
use Intakt\Core\HandBack\Contract\HandBackRefusal;
use Intakt\Core\HandBack\Contract\RefusedHandBack;
use Intakt\Core\HandBack\Domain\AttributionLines;
use Intakt\Core\HandBack\Domain\BundleHeader;
use Intakt\Core\HandBack\UseCase\CheckHandBack\BundleFile;
use Intakt\Core\HandBack\UseCase\CheckHandBack\BundleSecretScan;
use Intakt\Core\HandBack\UseCase\CheckHandBack\CheckHandBack;
use Intakt\Core\HandBack\UseCase\CheckHandBack\FailedHandBack;
use Intakt\Core\HandBack\UseCase\CheckHandBack\GivenHistory;
use Intakt\Core\HandBack\UseCase\CheckHandBack\HandBackRoundNotRecorded;
use Intakt\Core\HandBack\UseCase\CheckHandBack\HandBackRoute;
use Intakt\Core\HandBack\UseCase\CheckHandBack\HandBackRoutes;
use Intakt\Core\HandBack\UseCase\CheckHandBack\IncomingObjects;
use Intakt\Core\HandBack\UseCase\CheckHandBack\RepositoryGit;
use Intakt\Core\HandBack\UseCase\CheckHandBack\RouteHandBack;
use Intakt\Core\HandBack\UseCase\CheckHandBack\StrippedCommits;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Plugin\Forge\Contract\JobCredentials;
use Intakt\Tests\Core\HandBack\BuildHistory;
use Intakt\Tests\Core\HandBack\RecordingLedger;
use PDOException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

#[CoversClass(RouteHandBack::class)]
#[CoversClass(HandBackRoutes::class)]
#[CoversClass(FailedHandBack::class)]
#[CoversClass(HandBackRoute::class)]
#[CoversClass(HandBackRoundNotRecorded::class)]
#[CoversClass(BundleSecretScan::class)]
#[UsesClass(CheckHandBack::class)]
#[UsesClass(BundleFile::class)]
#[UsesClass(GivenHistory::class)]
#[UsesClass(IncomingObjects::class)]
#[UsesClass(RepositoryGit::class)]
#[UsesClass(StrippedCommits::class)]
#[UsesClass(AcceptedHandBack::class)]
#[UsesClass(RefusedHandBack::class)]
#[UsesClass(HandBack::class)]
#[UsesClass(HandBackRefusal::class)]
#[UsesClass(HandBackNotChecked::class)]
#[UsesClass(AttributionLines::class)]
#[UsesClass(BundleHeader::class)]
#[UsesClass(HardenedGit::class)]
#[UsesClass(GitCall::class)]
#[UsesClass(JobCredentials::class)]
final class RouteHandBackTest extends TestCase
{
    private BuildHistory $history;

    private RecordingLedger $ledger;

    public function testATokenInThePullRequestTextIsSentBackWithTheReasonAndWithoutItsValue(): void
    {
        $key = $this->fakeKey();
        $this->history->commit('First');

        $result = $this->route('Uses the key '.$key);

        self::assertEquals(new FailedHandBack(HandBackRefusal::TextHoldsSecret, HandBackRoute::SentBack), $result);
        self::assertSame([LedgerEventKind::HandBackSentBack], $this->ledger->kinds());
        self::assertSame(HandBackRefusal::TextHoldsSecret->reason(), $this->ledger->lastPayload()['reason']);
        self::assertStringNotContainsString($key, $this->ledger->payloads());
        self::assertInstanceOf(FailedHandBack::class, $result);
        self::assertStringNotContainsString($key, $result->reason());
    }

    public function testASecondFailureInARowHandsTheTaskOver(): void
    {
        $this->history->commit('First');

        $this->route('Uses the key '.$this->fakeKey());
        $result = $this->route('Uses the key '.$this->fakeKey());

        self::assertEquals(new FailedHandBack(HandBackRefusal::TextHoldsSecret, HandBackRoute::HandedOver), $result);
        self::assertSame([LedgerEventKind::HandBackSentBack, LedgerEventKind::HandBackHandedOver], $this->ledger->kinds());
    }

    public function testARefusedBundleCountsAsAFailureOfTheRound(): void
    {
        $this->history->commit('Use the key '.$this->fakeKey());

        $first = $this->route('Adds the first commit.');
        $second = $this->route('Adds the first commit.');

        self::assertEquals(new FailedHandBack(HandBackRefusal::HoldsSecret, HandBackRoute::SentBack), $first);
        self::assertEquals(new FailedHandBack(HandBackRefusal::HoldsSecret, HandBackRoute::HandedOver), $second);
    }

    public function testAFailureAfterAnAcceptedHandBackIsSentBackAgain(): void
    {
        $this->history->commit('First');

        $this->route('Uses the key '.$this->fakeKey());
        $this->route('Adds the first commit.');
        $result = $this->route('Uses the key '.$this->fakeKey());

        self::assertEquals(new FailedHandBack(HandBackRefusal::TextHoldsSecret, HandBackRoute::SentBack), $result);
    }

    public function testAFailureAfterAHandOverStartsTheCountAgain(): void
    {
        $this->history->commit('First');

        $this->route('Uses the key '.$this->fakeKey());
        $this->route('Uses the key '.$this->fakeKey());
        $result = $this->route('Uses the key '.$this->fakeKey());

        self::assertSame(HandBackRoute::SentBack, $result instanceof FailedHandBack ? $result->route : null);
    }

    public function testAFailureOfAnotherBranchDoesNotCount(): void
    {
        $this->history->commit('First');
        $this->history->startBranch('other', 'main');
        $this->history->commit('Other');
        $other = new HandBack($this->history->mirror, 'other', $this->history->bundle('main..other'), false, new JobCredentials());

        (void) $this->router()->route($other, 'Uses the key '.$this->fakeKey());
        $result = $this->route('Uses the key '.$this->fakeKey());

        self::assertSame(HandBackRoute::SentBack, $result instanceof FailedHandBack ? $result->route : null);
    }

    public function testAMountedCredentialInThePullRequestTextIsSentBackWithoutItsValue(): void
    {
        $credential = 'mounted-'.bin2hex(random_bytes(8));
        $this->history->commit('First');

        $result = $this->router()->route($this->handBack($credential), "Read with {$credential}.");

        self::assertEquals(new FailedHandBack(HandBackRefusal::TextHoldsCredential, HandBackRoute::SentBack), $result);
        self::assertStringNotContainsString($credential, $this->ledger->payloads());
        self::assertInstanceOf(FailedHandBack::class, $result);
        self::assertStringNotContainsString($credential, $result->reason());
    }

    public function testACleanBundleAndTextAreAcceptedAndRecorded(): void
    {
        $head = $this->history->commit('First');

        $result = $this->route('Adds the first commit.');

        self::assertEquals(new AcceptedHandBack(null, $head, 0), $result);
        self::assertSame([LedgerEventKind::HandBackAccepted], $this->ledger->kinds());
        self::assertSame($head, $this->ledger->lastPayload()['head']);
    }

    public function testAnEmptyPullRequestTextPassesTheScan(): void
    {
        $this->history->commit('First');

        $result = $this->route('');

        self::assertInstanceOf(AcceptedHandBack::class, $result);
    }

    public function testTheTasksLinesAreReadWhileItsLockIsHeld(): void
    {
        $this->history->commit('First');
        $store = new InMemoryStore();
        $key = 'intakt-hand-back-'.hash('sha256', json_encode([realpath($this->history->mirror), 'task'], JSON_THROW_ON_ERROR));
        $heldWhileRead = null;
        $this->ledger = new RecordingLedger(onRead: static function () use ($store, $key, &$heldWhileRead): void {
            $heldWhileRead = !new LockFactory($store)->createLock($key)->acquire();
        });

        (void) $this->router(locks: new LockFactory($store))->route($this->handBack(), 'Uses the key '.$this->fakeKey());

        self::assertTrue($heldWhileRead);
        self::assertTrue(new LockFactory($store)->createLock($key)->acquire());
    }

    public function testARoundTheLedgerDoesNotTakeFails(): void
    {
        $this->history->commit('First');
        $this->ledger = new RecordingLedger(down: true);

        $this->expectException(HandBackRoundNotRecorded::class);

        $this->route('Uses the key '.$this->fakeKey());
    }

    public function testALedgerThatCannotBeReadIsReportedAsARoundNotRecordedAndRecordsNothing(): void
    {
        $this->history->commit('First');
        $this->ledger = new RecordingLedger(onRead: static fn () => throw new PDOException('no such table: ledger_event'));

        try {
            $this->route('Uses the key '.$this->fakeKey());
            self::fail('the hand-back was routed without reading the ledger');
        } catch (HandBackRoundNotRecorded $failure) {
            self::assertStringContainsString('could not be read: no such table: ledger_event', $failure->getMessage());
            self::assertSame([], $this->ledger->kinds());
        }
    }

    public function testALineOfAnotherKindAboutTheSameBranchDoesNotCount(): void
    {
        $this->history->commit('First');
        $this->ledger->append(new LedgerEvent(LedgerEventKind::PullRequestTextSentBack, json_encode(['repository' => realpath($this->history->mirror), 'branch' => 'task'], JSON_THROW_ON_ERROR)));

        $result = $this->route('Uses the key '.$this->fakeKey());

        self::assertSame(HandBackRoute::SentBack, $result instanceof FailedHandBack ? $result->route : null);
    }

    public function testRoutingReadsOnlyTheThreeHandBackKindsAndNeverTheWholeLedger(): void
    {
        $this->history->commit('First');

        $this->route('Uses the key '.$this->fakeKey());

        self::assertSame(0, $this->ledger->fullReads);
        self::assertEqualsCanonicalizing([LedgerEventKind::HandBackAccepted, LedgerEventKind::HandBackSentBack, LedgerEventKind::HandBackHandedOver], $this->ledger->kindsRequested);
    }

    public function testAMissingScannerFailsClosedOnThePullRequestTextAndRecordsNothing(): void
    {
        $this->history->commit('First');

        try {
            (void) $this->router(new BundleSecretScan('intakt-missing-scanner'))->route($this->handBack(), 'Adds the first commit.');
            self::fail('the hand-back was routed without a scanner');
        } catch (HandBackNotChecked) {
            self::assertSame([], $this->ledger->kinds());
        }
    }

    protected function setUp(): void
    {
        $this->history = new BuildHistory();
        $this->ledger = new RecordingLedger();
    }

    protected function tearDown(): void
    {
        $this->history->remove();
    }

    // Built at run time and random, so neither this file nor the repository's history holds a key the secrets gates would find.
    private function fakeKey(): string
    {
        return 'sk_live_'.bin2hex(random_bytes(12));
    }

    private function route(string $text): AcceptedHandBack|FailedHandBack
    {
        return $this->router()->route($this->handBack(), $text);
    }

    private function handBack(string ...$credentials): HandBack
    {
        return new HandBack($this->history->mirror, 'task', $this->history->bundle('main..task'), false, new JobCredentials(...$credentials));
    }

    private function router(BundleSecretScan $scan = new BundleSecretScan('gitleaks'), LockFactory $locks = new LockFactory(new InMemoryStore())): RouteHandBack
    {
        $checks = new CheckHandBack(new RepositoryGit(new HardenedGit()), new BundleFile(), new StrippedCommits(), new IncomingObjects(), $scan);

        return new RouteHandBack($checks, $scan, new HandBackRoutes($this->ledger, $locks));
    }
}
