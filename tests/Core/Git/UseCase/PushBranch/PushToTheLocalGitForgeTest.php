<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Git\UseCase\PushBranch;

use Intakt\Core\Git\Contract\BranchNotPushed;
use Intakt\Core\Git\Contract\BranchPushRequest;
use Intakt\Core\Git\Contract\GitCall;
use Intakt\Core\Git\Contract\HardenedGit;
use Intakt\Core\Git\Contract\PushedBranch;
use Intakt\Core\Git\Contract\RefusedPush;
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
use Intakt\Plugin\Forge\Contract\BinaryFiles;
use Intakt\Plugin\Forge\Contract\Change;
use Intakt\Plugin\Forge\Contract\ChangedFile;
use Intakt\Plugin\Forge\Contract\ChangedFiles;
use Intakt\Plugin\Forge\Contract\ChangeId;
use Intakt\Plugin\Forge\Contract\ChangeText;
use Intakt\Plugin\Forge\Contract\CommitId;
use Intakt\Plugin\Forge\Contract\CommitRange;
use Intakt\Plugin\Forge\Contract\Diff;
use Intakt\Plugin\Forge\Contract\OpenedChange;
use Intakt\Plugin\LocalGitForge\Contract\LocalRepository;
use Intakt\Plugin\LocalGitForge\Infrastructure\LocalGitForge;
use Intakt\Plugin\LocalGitForge\Infrastructure\NameStatusParser;
use Intakt\Tests\Core\Git\TemporaryRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PushBranch::class)]
#[CoversClass(PushRecords::class)]
#[CoversClass(BranchGit::class)]
#[CoversClass(RefusedPush::class)]
#[UsesClass(GuardedRun::class)]
#[UsesClass(SignedCommits::class)]
#[UsesClass(SigningKey::class)]
#[UsesClass(CommitObject::class)]
#[UsesClass(BranchPushRequest::class)]
#[UsesClass(PushedBranch::class)]
#[UsesClass(BranchNotPushed::class)]
#[UsesClass(HardenedGit::class)]
#[UsesClass(GitCall::class)]
#[UsesClass(AcceptedHandBack::class)]
#[UsesClass(ChangeText::class)]
#[UsesClass(ChangeId::class)]
#[UsesClass(OpenedChange::class)]
#[UsesClass(LedgerEvent::class)]
#[UsesClass(AppendResult::class)]
#[UsesClass(RecordedEvent::class)]
#[UsesClass(RecordedEvents::class)]
#[UsesClass(TemporaryRepository::class)]
#[UsesClass(LocalGitForge::class)]
#[UsesClass(LocalRepository::class)]
#[UsesClass(NameStatusParser::class)]
#[UsesClass(BinaryFiles::class)]
#[UsesClass(Change::class)]
#[UsesClass(ChangedFile::class)]
#[UsesClass(ChangedFiles::class)]
#[UsesClass(CommitId::class)]
#[UsesClass(CommitRange::class)]
#[UsesClass(Diff::class)]
final class PushToTheLocalGitForgeTest extends TestCase
{
    private PushScenario $scenario;
    private string $forge;

    public function testABranchThatPassesIsPushedToTheLocalGitForgeWithEveryCommitSigned(): void
    {
        $pushed = $this->scenario->push()->push($this->request());

        self::assertInstanceOf(PushedBranch::class, $pushed);
        self::assertSame($pushed->head, $this->forgeChange()->commits->head->hash);
        self::assertSame(['G', 'G'], array_map(static fn (string $line): string => explode(' ', $line)[0], explode("\n", $this->scenario->signatureMarks($this->forge))));
    }

    public function testAPushTheLocalGitForgeRefusesReturnsItsOwnOutcome(): void
    {
        $this->scenario->checkOutTheBranchIn($this->forge);

        $outcome = $this->scenario->push()->push($this->request());

        self::assertInstanceOf(RefusedPush::class, $outcome);
        self::assertSame('the remote refused the push of task (git push exited with 1)', $outcome->reason);
        self::assertNull($this->scenario->remoteTip($this->forge));
    }

    public function testAPushTheForgesHookRefusesGivesAReasonWithoutGitsErrorOutput(): void
    {
        $this->scenario->refuseWithAHookIn($this->forge);

        $outcome = $this->scenario->push()->push($this->request());

        self::assertInstanceOf(RefusedPush::class, $outcome);
        self::assertSame('the remote refused the push of task (git push exited with 1)', $outcome->reason);
        self::assertStringNotContainsString($this->forge, $outcome->reason);
        self::assertNull($this->scenario->remoteTip($this->forge));
    }

    public function testARefusedPushIsRecordedWithItsHeadAndReason(): void
    {
        $this->scenario->checkOutTheBranchIn($this->forge);

        $outcome = $this->scenario->push()->push($this->request());

        self::assertInstanceOf(RefusedPush::class, $outcome);
        self::assertSame([LedgerEventKind::PushIntended, LedgerEventKind::PushRefused], $this->scenario->ledger->kinds());
        $intended = $this->scenario->ledger->payloadOf(LedgerEventKind::PushIntended);
        self::assertSame([...$intended, 'reason' => $outcome->reason], $this->scenario->ledger->payloadOf(LedgerEventKind::PushRefused));
    }

    public function testARefusedPushAsksTheForgeForNoPullRequest(): void
    {
        $this->scenario->checkOutTheBranchIn($this->forge);

        (void) $this->scenario->push()->push($this->request());

        self::assertSame(0, $this->scenario->changes->calls);
    }

    public function testARefusedPushIsPushedByTheNextCallOnceTheForgeAcceptsIt(): void
    {
        $this->scenario->checkOutTheBranchIn($this->forge);
        $refused = $this->scenario->push()->push($this->request());
        $this->scenario->checkOutMainIn($this->forge);

        $pushed = $this->scenario->push()->push($this->request());

        self::assertInstanceOf(RefusedPush::class, $refused);
        self::assertInstanceOf(PushedBranch::class, $pushed);
        self::assertSame($pushed->head, $this->forgeChange()->commits->head->hash);
        self::assertSame([LedgerEventKind::PushIntended, LedgerEventKind::PushRefused, LedgerEventKind::BranchPushed, LedgerEventKind::ChangeIntended, LedgerEventKind::ChangeOpened], $this->scenario->ledger->kinds());
    }

    public function testARefusalTheLedgerDoesNotRecordStopsThePush(): void
    {
        $this->scenario->checkOutTheBranchIn($this->forge);
        $this->scenario->ledger->accepts = 1;

        try {
            (void) $this->scenario->push()->push($this->request());
            self::fail('The push must stop when the ledger refuses the record of the refusal.');
        } catch (BranchNotPushed $refused) {
            self::assertStringContainsString('push_refused', $refused->getMessage());
        }
    }

    protected function setUp(): void
    {
        $this->scenario = new PushScenario();
        $this->forge = $this->scenario->localGitForge();
    }

    protected function tearDown(): void
    {
        $this->scenario->remove();
    }

    private function request(): BranchPushRequest
    {
        return $this->scenario->requestOfProject('widgets', $this->forge);
    }

    private function forgeChange(): Change
    {
        return new LocalGitForge(new LocalRepository($this->forge, 'main'), new NameStatusParser())->change(new ChangeId(PushScenario::BRANCH));
    }
}
