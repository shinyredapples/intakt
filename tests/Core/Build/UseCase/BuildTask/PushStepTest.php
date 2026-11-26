<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Build\UseCase\BuildTask;

use Intakt\Core\Build\UseCase\BuildTask\BuildTaskRequest;
use Intakt\Core\Build\UseCase\BuildTask\PushStep;
use Intakt\Core\Git\Contract\BranchNotPushed;
use Intakt\Core\Git\Contract\BranchPush;
use Intakt\Core\Git\Contract\BranchPushRequest;
use Intakt\Core\Git\Contract\BuildCheckout;
use Intakt\Core\Git\Contract\BuildCheckoutFailed;
use Intakt\Core\Git\Contract\GitCall;
use Intakt\Core\Git\Contract\HardenedGit;
use Intakt\Core\Git\Contract\MainConflicts;
use Intakt\Core\Git\Contract\Paths;
use Intakt\Core\Git\Contract\PushedBranch;
use Intakt\Core\Git\Contract\RefusedPush;
use Intakt\Core\Git\Contract\UnsignedCommitRefused;
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
use Intakt\Core\Ledger\Contract\RecordedEvent;
use Intakt\Core\Ledger\Contract\RecordedEvents;
use Intakt\Plugin\Forge\Contract\ChangeId;
use Intakt\Plugin\Forge\Contract\ChangeText;
use Intakt\Plugin\Forge\Contract\ForgeWriteFailed;
use Intakt\Plugin\Forge\Contract\OpenedChange;
use Intakt\Tests\Core\Git\TemporaryRepository;
use Intakt\Tests\Core\Git\UseCase\PushBranch\PushScenario;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Lock\Exception\LockStorageException;
use Symfony\Component\Lock\PersistingStoreInterface;
use Throwable;

#[CoversClass(PushStep::class)]
#[UsesClass(BuildTaskRequest::class)]
#[UsesClass(PushBranch::class)]
#[UsesClass(PushRecords::class)]
#[UsesClass(BranchGit::class)]
#[UsesClass(GuardedRun::class)]
#[UsesClass(SignedCommits::class)]
#[UsesClass(SigningKey::class)]
#[UsesClass(CommitObject::class)]
#[UsesClass(BranchPushRequest::class)]
#[UsesClass(PushedBranch::class)]
#[UsesClass(RefusedPush::class)]
#[UsesClass(MainConflicts::class)]
#[UsesClass(BranchNotPushed::class)]
#[UsesClass(BuildCheckoutFailed::class)]
#[UsesClass(UnsignedCommitRefused::class)]
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
final class PushStepTest extends TestCase
{
    private PushScenario $scenario;
    private string $forge;

    public function testABranchTheLocalGitForgeAcceptsIsPushedAndGivesNoFailure(): void
    {
        $failure = $this->stepOf($this->scenario->push())->failureOf($this->request(), $this->accepted(), $this->text());

        self::assertNull($failure);
        self::assertNotNull($this->scenario->remoteTip($this->forge));
        self::assertSame(['G', 'G'], array_map(static fn (string $line): string => explode(' ', $line)[0], explode("\n", $this->scenario->signatureMarks($this->forge))));
    }

    public function testThePushOpensNoPullRequest(): void
    {
        $this->stepOf($this->scenario->push())->failureOf($this->request(), $this->accepted(), $this->text());

        self::assertSame(0, $this->scenario->changes->calls);
    }

    public function testAPushTheLocalGitForgeRefusesGivesTheRecordedReasonAsTheFailure(): void
    {
        $this->scenario->checkOutTheBranchIn($this->forge);

        $failure = $this->stepOf($this->scenario->push())->failureOf($this->request(), $this->accepted(), $this->text());

        self::assertSame('the remote refused the push of task (git push exited with 1)', $failure);
        self::assertNull($this->scenario->remoteTip($this->forge));
    }

    public function testAPushThatFailsGivesItsMessageAsTheFailure(): void
    {
        $this->scenario->ledger->accepts = 0;

        $failure = $this->stepOf($this->scenario->push())->failureOf($this->request(), $this->accepted(), $this->text());

        self::assertStringStartsWith('The branch was not pushed: the ledger did not record "push_intended"', (string) $failure);
        self::assertNull($this->scenario->remoteTip($this->forge));
    }

    public function testABranchThatConflictsWithMainGivesTheCountOfPathsAndNoneOfTheirNames(): void
    {
        $failure = $this->stepOf($this->pushReturning(new MainConflicts(new Paths('src/<builder chosen>.php', 'b.txt'))))->failureOf($this->request(), $this->accepted(), $this->text());

        self::assertSame('the branch conflicts with main, so nothing was pushed (conflicting paths: 2)', $failure);
    }

    public function testAnUnsignedCommitGivesTheRefusalAsTheFailure(): void
    {
        $failure = $this->stepOf($this->pushThrowing(UnsignedCommitRefused::commit('abc', 'it has no signature')))->failureOf($this->request(), $this->accepted(), $this->text());

        self::assertSame('The commit abc is not signed by the orchestrator\'s key (it has no signature), so nothing is pushed.', $failure);
    }

    public function testAForgeThatRefusesTheBranchBeforeThePushGivesItsMessageAsTheFailure(): void
    {
        $failure = $this->stepOf($this->pushThrowing(new ForgeWriteFailed('the branch has an open pull request another account opened')))->failureOf($this->request(), $this->accepted(), $this->text());

        self::assertSame('the branch has an open pull request another account opened', $failure);
    }

    public function testAMirrorThatCannotBeNamedGivesItsMessageAndPushesNothing(): void
    {
        $checkout = self::createStub(BuildCheckout::class);
        $checkout->method('mirrorOf')->willThrowException(BuildCheckoutFailed::because(new RuntimeException('the project name is no folder name')));

        $failure = new PushStep($this->scenario->push(), $checkout)->failureOf($this->request(), $this->accepted(), $this->text());

        self::assertSame('the project name is no folder name', $failure);
        self::assertSame([], $this->scenario->ledger->kinds());
    }

    public function testALockStoreFailureDuringThePushGivesItsMessageAsTheFailure(): void
    {
        $store = self::createStub(PersistingStoreInterface::class);
        $store->method('save')->willThrowException(new LockStorageException('the lock database is down'));

        $failure = $this->stepOf($this->scenario->pushWithLockStore($store))->failureOf($this->request(), $this->accepted(), $this->text());

        self::assertStringStartsWith('The branch was not pushed: the lock store failed: ', (string) $failure);
        self::assertNull($this->scenario->remoteTip($this->forge));
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

    private function stepOf(BranchPush $branches): PushStep
    {
        $checkout = self::createStub(BuildCheckout::class);
        $checkout->method('mirrorOf')->willReturn($this->scenario->gitDirectory());

        return new PushStep($branches, $checkout);
    }

    private function pushReturning(MainConflicts $outcome): BranchPush
    {
        $branches = self::createStub(BranchPush::class);
        $branches->method('push')->willReturn($outcome);

        return $branches;
    }

    private function pushThrowing(Throwable $failure): BranchPush
    {
        $branches = self::createStub(BranchPush::class);
        $branches->method('push')->willThrowException($failure);

        return $branches;
    }

    private function request(): BuildTaskRequest
    {
        return new BuildTaskRequest('widgets', 7, $this->forge, PushScenario::BRANCH, $this->scenario->repository->path, 'intakt-job:test');
    }

    private function accepted(): AcceptedHandBack
    {
        return new AcceptedHandBack(null, $this->scenario->head, 0);
    }

    private function text(): ChangeText
    {
        return new ChangeText('Add the files', 'Evidence: tests pass.');
    }
}
