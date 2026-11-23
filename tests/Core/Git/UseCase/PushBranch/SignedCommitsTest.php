<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Git\UseCase\PushBranch;

use Intakt\Core\Git\Contract\BranchNotPushed;
use Intakt\Core\Git\Contract\HardenedGit;
use Intakt\Core\Git\Contract\UnsignedCommitRefused;
use Intakt\Core\Git\Domain\CommitObject;
use Intakt\Core\Git\UseCase\PushBranch\BranchGit;
use Intakt\Core\Git\UseCase\PushBranch\GuardedRun;
use Intakt\Core\Git\UseCase\PushBranch\SignedCommits;
use Intakt\Core\Git\UseCase\PushBranch\SigningKey;
use Intakt\Core\HandBack\Contract\AcceptedHandBack;
use Intakt\Tests\Core\Git\TemporaryRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SignedCommits::class)]
#[CoversClass(SigningKey::class)]
#[UsesClass(BranchGit::class)]
#[UsesClass(GuardedRun::class)]
#[UsesClass(AcceptedHandBack::class)]
#[UsesClass(CommitObject::class)]
#[UsesClass(HardenedGit::class)]
#[UsesClass(BranchNotPushed::class)]
#[UsesClass(UnsignedCommitRefused::class)]
#[UsesClass(TemporaryRepository::class)]
final class SignedCommitsTest extends TestCase
{
    private const string MAIN = 'refs/heads/main';

    private PushScenario $scenario;

    public function testTheCheckRefusesACommitThatIsNotSigned(): void
    {
        $this->assertRefusedBecause('it has no signature', fn () => $this->scenario->signedCommits()->verify($this->scenario->gitDirectory(), $this->scenario->head, self::MAIN));
    }

    public function testTheCheckRefusesACommitSignedByAnotherKey(): void
    {
        $other = PushScenario::generateKey($this->scenario->key.'-other');
        $signedByOther = $this->scenario->signedCommits($other)->of($this->scenario->gitDirectory(), new AcceptedHandBack(null, $this->scenario->head, 0), self::MAIN);

        $this->assertRefusedBecause('its signature does not verify', fn () => $this->scenario->signedCommits()->verify($this->scenario->gitDirectory(), $signedByOther, self::MAIN));
    }

    public function testASigningKeyWhoseSignaturesDifferOnEachRunIsRefusedBeforeItSigns(): void
    {
        $ecdsa = PushScenario::generateKey($this->scenario->key.'-ecdsa', 'ecdsa');

        try {
            $this->scenario->signedCommits($ecdsa)->of($this->scenario->gitDirectory(), new AcceptedHandBack(null, $this->scenario->head, 0), self::MAIN);
            self::fail('An ECDSA key must not sign.');
        } catch (BranchNotPushed $refused) {
            self::assertStringContainsString('ed25519', $refused->getMessage());
        }
    }

    public function testTheCheckAcceptsCommitsTheOrchestratorSigned(): void
    {
        $signed = $this->scenario->signedCommits()->of($this->scenario->gitDirectory(), new AcceptedHandBack(null, $this->scenario->head, 0), self::MAIN);

        $this->scenario->signedCommits()->verify($this->scenario->gitDirectory(), $signed, self::MAIN);

        $this->addToAssertionCount(1);
    }

    public function testACommitTheBuilderSignedItselfIsSignedAgainByTheOrchestrator(): void
    {
        $foreign = $this->scenario->repository->commitWithASignatureHeader();
        $signed = $this->scenario->signedCommits()->of($this->scenario->gitDirectory(), new AcceptedHandBack(null, $foreign, 0), self::MAIN);

        $this->scenario->signedCommits()->verify($this->scenario->gitDirectory(), $signed, self::MAIN);

        $this->addToAssertionCount(1);
    }

    public function testCommitsAlreadyPushedAreNotSignedAgain(): void
    {
        $base = $this->scenario->repository->objectAt(PushScenario::BRANCH.'~1');
        $signed = $this->scenario->signedCommits()->of($this->scenario->gitDirectory(), new AcceptedHandBack($base, $this->scenario->head, 0), self::MAIN);

        self::assertSame($base, $this->scenario->repository->objectAt($signed.'^'));
    }

    protected function setUp(): void
    {
        $this->scenario = new PushScenario();
    }

    protected function tearDown(): void
    {
        $this->scenario->remove();
    }

    private function assertRefusedBecause(string $reason, callable $check): void
    {
        try {
            $check();
            self::fail('The check must refuse the commit.');
        } catch (UnsignedCommitRefused $refused) {
            self::assertStringContainsString($reason, $refused->getMessage());
        }
    }
}
