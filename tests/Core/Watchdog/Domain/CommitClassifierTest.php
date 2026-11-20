<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Watchdog\Domain;

use Intakt\Core\Watchdog\Contract\Classification;
use Intakt\Core\Watchdog\Contract\CommitHistory;
use Intakt\Core\Watchdog\Contract\CommitName;
use Intakt\Core\Watchdog\Contract\MainViolation;
use Intakt\Core\Watchdog\Contract\RecordedMerge;
use Intakt\Core\Watchdog\Contract\SignatureStatus;
use Intakt\Core\Watchdog\Contract\Violation;
use Intakt\Core\Watchdog\Contract\WatchedMain;
use Intakt\Core\Watchdog\Domain\CommitClassifier;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CommitClassifier::class)]
#[CoversClass(Classification::class)]
#[CoversClass(MainViolation::class)]
#[CoversClass(WatchedMain::class)]
#[UsesClass(RecordedMerge::class)]
#[UsesClass(CommitName::class)]
final class CommitClassifierTest extends TestCase
{
    private const string SEEN = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const string BASE = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private const string MERGE = 'cccccccccccccccccccccccccccccccccccccccc';
    private const string OTHER = 'dddddddddddddddddddddddddddddddddddddddd';

    public function testARecordedMergeIsClean(): void
    {
        $history = $this->history([self::MERGE => SignatureStatus::OwnerSigned]);

        $classification = $this->classify($history, $this->mainWithRecordedMerge());

        self::assertTrue($classification->isClean());
    }

    public function testARecordedMergeHeadPassesBeforeAnySignatureCheck(): void
    {
        $history = self::createStub(CommitHistory::class);
        $history->method('contains')->willReturn(true);
        $history->method('firstParentsSince')->willReturn([self::MERGE]);
        $history->method('signatureOf')->willThrowException(new LogicException('A recorded head is never read for its signature.'));

        $classification = $this->classify($history, $this->mainWithRecordedMerge());

        self::assertTrue($classification->isClean());
    }

    public function testAnOwnerSignedCommitPasses(): void
    {
        $history = $this->history([self::OTHER => SignatureStatus::OwnerSigned]);

        $classification = $this->classify($history, $this->mainWithRecordedMerge());

        self::assertTrue($classification->isClean());
    }

    public function testAnUnsignedCommitIsAViolation(): void
    {
        $history = $this->history([self::OTHER => SignatureStatus::Unsigned]);

        $classification = $this->classify($history, $this->mainWithRecordedMerge());

        self::assertEquals([new MainViolation(self::OTHER, Violation::Unsigned)], $classification->violations);
    }

    public function testACommitSignedByAForeignKeyIsAViolation(): void
    {
        $history = $this->history([self::OTHER => SignatureStatus::UnknownKey]);

        $classification = $this->classify($history, $this->mainWithRecordedMerge());

        self::assertEquals([new MainViolation(self::OTHER, Violation::UnknownKey)], $classification->violations);
    }

    public function testEveryViolatingCommitOfTheWalkIsNamedInItsOrder(): void
    {
        $history = $this->history([
            self::OTHER => SignatureStatus::Unsigned,
            self::MERGE => SignatureStatus::Unsigned,
            self::BASE => SignatureStatus::UnknownKey,
        ]);

        $classification = $this->classify($history, $this->mainWithRecordedMerge());

        self::assertEquals(
            [new MainViolation(self::OTHER, Violation::Unsigned), new MainViolation(self::BASE, Violation::UnknownKey)],
            $classification->violations,
        );
    }

    public function testAForcePushThatDropsTheSeenHeadIsAHistoryRewrite(): void
    {
        $history = self::createStub(CommitHistory::class);
        $history->method('contains')->willReturn(false);
        $history->method('firstParentsSince')->willReturn([self::MERGE]);
        $history->method('signatureOf')->willReturn(SignatureStatus::OwnerSigned);

        $classification = $this->classify($history, $this->mainWithRecordedMerge());

        self::assertEquals([new MainViolation(self::SEEN, Violation::HistoryRewritten)], $classification->violations);
    }

    public function testAMainWithoutNewCommitsIsClean(): void
    {
        $classification = $this->classify($this->history([]), $this->mainWithRecordedMerge());

        self::assertTrue($classification->isClean());
    }

    /**
     * @param array<string, SignatureStatus> $signatures
     */
    private function history(array $signatures): CommitHistory
    {
        $history = self::createStub(CommitHistory::class);
        $history->method('contains')->willReturn(true);
        $history->method('firstParentsSince')->willReturn(array_keys($signatures));
        $history->method('signatureOf')->willReturnCallback(
            static fn (string $commit): SignatureStatus => $signatures[$commit],
        );

        return $history;
    }

    private function mainWithRecordedMerge(): WatchedMain
    {
        return new WatchedMain(self::SEEN, [new RecordedMerge(self::MERGE, self::BASE)]);
    }

    private function classify(CommitHistory $history, WatchedMain $main): Classification
    {
        return new CommitClassifier($history)->classify($main);
    }
}
