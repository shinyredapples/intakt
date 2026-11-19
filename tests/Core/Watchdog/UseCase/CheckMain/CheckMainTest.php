<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Watchdog\UseCase\CheckMain;

use Intakt\Core\Git\Contract\HardenedGit;
use Intakt\Core\Watchdog\Contract\CheckedMain;
use Intakt\Core\Watchdog\Contract\CommitClassification;
use Intakt\Core\Watchdog\Contract\MainNotRead;
use Intakt\Core\Watchdog\Contract\RecordedMerge;
use Intakt\Core\Watchdog\Contract\SignatureStatus;
use Intakt\Core\Watchdog\Contract\Verdict;
use Intakt\Core\Watchdog\Contract\WatchedMain;
use Intakt\Core\Watchdog\UseCase\CheckMain\CheckMain;
use Intakt\Core\Watchdog\UseCase\CheckMain\CommitSignature;
use Intakt\Core\Watchdog\UseCase\CheckMain\MainHistory;
use Intakt\Tests\Core\Git\TemporaryRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

#[CoversClass(CheckMain::class)]
#[CoversClass(MainHistory::class)]
#[CoversClass(CommitSignature::class)]
#[CoversClass(CheckedMain::class)]
#[CoversClass(Verdict::class)]
#[UsesClass(WatchedMain::class)]
#[UsesClass(RecordedMerge::class)]
#[UsesClass(CommitClassification::class)]
#[UsesClass(SignatureStatus::class)]
#[UsesClass(HardenedGit::class)]
#[UsesClass(TemporaryRepository::class)]
#[UsesClass(MainScenario::class)]
final class CheckMainTest extends TestCase
{
    private MainScenario $scenario;

    protected function setUp(): void
    {
        $this->scenario = new MainScenario();
    }

    protected function tearDown(): void
    {
        $this->scenario->remove();
    }

    public function testACommitThatIsTheHeadOfARecordedMergeIsClean(): void
    {
        $base = $this->scenario->main->commitFile('a.txt', "one\n");
        $merge = $this->scenario->main->commitFile('b.txt', "two\n");

        $checked = $this->scenario->check()->of($this->watching($base, [new RecordedMerge($merge, $base)], []));

        self::assertSame([Verdict::RecordedMerge], $this->verdicts($checked));
        self::assertTrue($checked->isClean());
    }

    public function testAnUnsignedCommitSeededOnMainIsAViolation(): void
    {
        $base = $this->scenario->main->commitFile('a.txt', "one\n");
        $seeded = $this->scenario->main->commitFile('seeded.txt', "unsigned\n");

        $checked = $this->scenario->check()->of($this->watching($base, [], [$this->scenario->newKey('owner')]));

        self::assertSame([Verdict::Unsigned], $this->verdicts($checked));
        self::assertSame($seeded, $checked->violations()[0]->commit);
    }

    public function testACommitSignedByTheOwnersKeyIsClean(): void
    {
        $base = $this->scenario->main->commitFile('a.txt', "one\n");
        $owner = $this->scenario->newKey('owner');
        $this->scenario->commitSignedBy('owner');

        $checked = $this->scenario->check()->of($this->watching($base, [], [$owner]));

        self::assertSame([Verdict::OwnerSigned], $this->verdicts($checked));
    }

    public function testACommitSignedByAForeignKeyIsAViolation(): void
    {
        $base = $this->scenario->main->commitFile('a.txt', "one\n");
        $owner = $this->scenario->newKey('owner');
        $this->scenario->newKey('stranger');
        $this->scenario->commitSignedBy('stranger');

        $checked = $this->scenario->check()->of($this->watching($base, [], [$owner]));

        self::assertSame([Verdict::UnknownKey], $this->verdicts($checked));
    }

    public function testACommitWithASignatureHeaderThatIsNoSignatureIsAViolation(): void
    {
        $base = $this->scenario->main->commitFile('a.txt', "one\n");
        $this->scenario->main->commitWithASignatureHeader();

        $checked = $this->scenario->check()->of($this->watching($base, [], [$this->scenario->newKey('owner')]));

        self::assertSame([Verdict::UnknownKey], $this->verdicts($checked));
    }

    public function testASignedCommitWithoutAnyRegisteredOwnerKeyIsAViolation(): void
    {
        $base = $this->scenario->main->commitFile('a.txt', "one\n");
        $this->scenario->newKey('owner');
        $this->scenario->commitSignedBy('owner');

        $checked = $this->scenario->check()->of($this->watching($base, [], []));

        self::assertSame([Verdict::UnknownKey], $this->verdicts($checked));
    }

    public function testOnlyTheCommitsAfterTheLastSeenHeadAreListedOldestFirst(): void
    {
        $this->scenario->main->commitFile('a.txt', "one\n");
        $seen = $this->scenario->main->commitFile('b.txt', "two\n");
        $owner = $this->scenario->newKey('owner');
        $first = $this->scenario->commitSignedBy('owner');
        $second = $this->scenario->main->commitFile('c.txt', "three\n");

        $checked = $this->scenario->check()->of($this->watching($seen, [], [$owner]));

        self::assertSame([$first, $second], array_map(static fn (CommitClassification $commit): string => $commit->commit, $checked->commits));
        self::assertSame([Verdict::OwnerSigned, Verdict::Unsigned], $this->verdicts($checked));
    }

    public function testMainWithNothingNewIsClean(): void
    {
        $head = $this->scenario->main->commitFile('a.txt', "one\n");

        $checked = $this->scenario->check()->of($this->watching($head, [], []));

        self::assertSame([], $checked->commits);
        self::assertSame($head, $checked->head);
        self::assertTrue($checked->isClean());
    }

    public function testAForcePushOfMainIsHistoryRewritten(): void
    {
        $first = $this->scenario->main->commitFile('a.txt', "one\n");
        $seen = $this->scenario->main->commitFile('b.txt', "two\n");
        $this->scenario->main->checkoutDetached($first);
        $replacement = $this->scenario->main->commitFile('c.txt', "other\n");
        $this->scenario->forceMainTo($replacement);

        $checked = $this->scenario->check()->of($this->watching($seen, [], []));

        self::assertSame([Verdict::HistoryRewritten], $this->verdicts($checked));
        self::assertSame('history_rewritten', $checked->violations()[0]->verdict->value);
        self::assertSame($replacement, $checked->head);
    }

    public function testALastSeenHeadThatIsGoneFromTheRepositoryIsHistoryRewritten(): void
    {
        $this->scenario->main->commitFile('a.txt', "one\n");

        $checked = $this->scenario->check()->of($this->watching(str_repeat('a', 40), [], []));

        self::assertSame([Verdict::HistoryRewritten], $this->verdicts($checked));
    }

    public function testAShallowRepositoryIsRefusedBeforeAnyCommitIsListed(): void
    {
        $base = $this->scenario->main->commitFile('a.txt', "one\n");
        $head = $this->scenario->main->commitFile('b.txt', "two\n");
        $this->scenario->main->cutHistoryBelow($head);

        try {
            (void) $this->scenario->check()->of($this->watching($base, [], []));
            self::fail('A shallow repository was read.');
        } catch (MainNotRead $refused) {
            self::assertSame('Main was not read: the repository is shallow, so its history is incomplete', $refused->getMessage());
        }
    }

    public function testARepositoryThatIsNotThereIsRefused(): void
    {
        $this->expectException(MainNotRead::class);

        (void) $this->scenario->check()->of(new WatchedMain($this->scenario->folder.'/missing', null, [], []));
    }

    public function testARelativePathToTheRepositoryIsRead(): void
    {
        $head = $this->scenario->main->commitFile('a.txt', "one\n");
        $relative = new Filesystem()->makePathRelative($this->scenario->main->path, (string) getcwd());

        $checked = $this->scenario->check()->of(new WatchedMain(rtrim($relative, '/'), $head, [], []));

        self::assertSame($head, $checked->head);
    }

    /**
     * @param list<RecordedMerge> $recorded
     * @param list<string>        $ownerKeys
     */
    private function watching(string $lastSeen, array $recorded, array $ownerKeys): WatchedMain
    {
        return new WatchedMain($this->scenario->main->path, $lastSeen, $recorded, $ownerKeys);
    }

    /**
     * @return list<Verdict>
     */
    private function verdicts(CheckedMain $checked): array
    {
        return array_map(static fn (CommitClassification $commit): Verdict => $commit->verdict, $checked->commits);
    }
}
