<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Build\UseCase\BuildTask;

use Intakt\Core\Build\UseCase\BuildTask\BuildTaskRequest;
use Intakt\Core\Build\UseCase\BuildTask\PullRequestStep;
use Intakt\Core\Git\Contract\BranchNotPushed;
use Intakt\Core\Git\Contract\BranchPush;
use Intakt\Core\Git\Contract\BranchPushRequest;
use Intakt\Core\Git\Contract\BuildCheckout;
use Intakt\Core\Git\Contract\BuildCheckoutFailed;
use Intakt\Core\Git\Contract\GitCall;
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
use Intakt\Plugin\Forge\Contract\ChangeTextHoldsSecret;
use Intakt\Plugin\Forge\Contract\ForgeWriteFailed;
use Intakt\Plugin\Forge\Contract\ProposedChange;
use Intakt\Tests\Core\Git\TemporaryRepository;
use Intakt\Tests\Core\Git\UseCase\PushBranch\PushScenario;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

#[CoversClass(PullRequestStep::class)]
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
#[UsesClass(BranchNotPushed::class)]
#[UsesClass(BuildCheckoutFailed::class)]
#[UsesClass(ChangeTextHoldsSecret::class)]
#[UsesClass(HardenedGit::class)]
#[UsesClass(GitCall::class)]
#[UsesClass(AcceptedHandBack::class)]
#[UsesClass(ChangeText::class)]
#[UsesClass(ChangeId::class)]
#[UsesClass(ProposedChange::class)]
#[UsesClass(LedgerEvent::class)]
#[UsesClass(AppendResult::class)]
#[UsesClass(RecordedEvent::class)]
#[UsesClass(RecordedEvents::class)]
#[UsesClass(TemporaryRepository::class)]
final class PullRequestStepTest extends TestCase
{
    private PushScenario $scenario;

    public function testAPushedBranchGetsItsPullRequestWithTheTextAndNoFailure(): void
    {
        $push = $this->scenario->push();
        (void) $push->push($this->scenario->request());

        $failure = $this->stepOf($push)->failureOf($this->request(), $this->accepted(), $this->text());

        self::assertNull($failure);
        self::assertSame(1, $this->scenario->changes->opened);
        self::assertEquals($this->text(), $this->scenario->changes->text);
        self::assertSame([LedgerEventKind::PushIntended, LedgerEventKind::BranchPushed, LedgerEventKind::ChangeIntended, LedgerEventKind::ChangeOpened], $this->scenario->ledger->kinds());
    }

    public function testABranchThatIsNotPushedGetsNoPullRequestAndGivesTheReasonAsTheFailure(): void
    {
        $failure = $this->stepOf($this->scenario->push())->failureOf($this->request(), $this->accepted(), $this->text());

        self::assertSame('The branch was not pushed: this head of task is not pushed yet, so no pull request is opened', $failure);
        self::assertSame(0, $this->scenario->changes->calls);
    }

    public function testAForgeWriteThatFailsGivesItsMessageAsTheFailure(): void
    {
        $failure = $this->stepOf($this->openingThrowing(new ForgeWriteFailed('the forge refused the pull request')))->failureOf($this->request(), $this->accepted(), $this->text());

        self::assertSame('the forge refused the pull request', $failure);
    }

    public function testAChangeTextHoldingASecretGivesTheRefusalAsTheFailure(): void
    {
        $failure = $this->stepOf($this->openingThrowing(ChangeTextHoldsSecret::inTheText()))->failureOf($this->request(), $this->accepted(), $this->text());

        self::assertSame('The pull request text trips the secret scan, so no pull request is opened or updated.', $failure);
    }

    public function testAMirrorThatCannotBeNamedGivesItsMessageAndOpensNothing(): void
    {
        $checkout = self::createStub(BuildCheckout::class);
        $checkout->method('mirrorOf')->willThrowException(BuildCheckoutFailed::because(new RuntimeException('the project name is no folder name')));

        $failure = new PullRequestStep($this->scenario->push(), $checkout)->failureOf($this->request(), $this->accepted(), $this->text());

        self::assertSame('the project name is no folder name', $failure);
        self::assertSame(0, $this->scenario->changes->calls);
    }

    protected function setUp(): void
    {
        $this->scenario = new PushScenario();
    }

    protected function tearDown(): void
    {
        $this->scenario->remove();
    }

    private function stepOf(BranchPush $branches): PullRequestStep
    {
        $checkout = self::createStub(BuildCheckout::class);
        $checkout->method('mirrorOf')->willReturn($this->scenario->gitDirectory());

        return new PullRequestStep($branches, $checkout);
    }

    private function openingThrowing(Throwable $failure): BranchPush
    {
        $branches = self::createStub(BranchPush::class);
        $branches->method('openChange')->willThrowException($failure);

        return $branches;
    }

    private function request(): BuildTaskRequest
    {
        return new BuildTaskRequest('widgets', 7, $this->scenario->remote, PushScenario::BRANCH, $this->scenario->repository->path, 'intakt-job:test');
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
