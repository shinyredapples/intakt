<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Watchdog\Contract;

use Intakt\Core\Watchdog\Contract\CommitName;
use Intakt\Core\Watchdog\Contract\CommitNameNotFull;
use Intakt\Core\Watchdog\Contract\RecordedMerge;
use Intakt\Core\Watchdog\Contract\WatchedMain;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(WatchedMain::class)]
#[UsesClass(RecordedMerge::class)]
#[UsesClass(CommitName::class)]
#[UsesClass(CommitNameNotFull::class)]
final class WatchedMainTest extends TestCase
{
    private const string SEEN = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const string HEAD = 'cccccccccccccccccccccccccccccccccccccccc';
    private const string BASE = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    public function testTheHeadOfARecordedMergeIsARecordedHead(): void
    {
        $main = new WatchedMain(self::SEEN, [new RecordedMerge(self::HEAD, self::BASE)]);

        self::assertTrue($main->isRecordedHead(self::HEAD));
    }

    public function testTheBaseOfARecordedMergeIsNoRecordedHead(): void
    {
        $main = new WatchedMain(self::SEEN, [new RecordedMerge(self::HEAD, self::BASE)]);

        self::assertFalse($main->isRecordedHead(self::BASE));
    }

    public function testAWatchedMainRefusesASeenHeadThatIsNotAFullCommitName(): void
    {
        $this->expectExceptionObject(new CommitNameNotFull('The last seen head "HEAD" is not a full commit name.'));

        new WatchedMain('HEAD', []);
    }

    public function testFullNamesOfTheRepositorysLengthPass(): void
    {
        $main = new WatchedMain(self::SEEN, [new RecordedMerge(self::HEAD, self::BASE)]);

        $main->mustNameFullCommitsOfLength(40);

        $this->addToAssertionCount(1);
    }

    public function testASha1LengthBaseInASha256RepositoryIsRefused(): void
    {
        $main = new WatchedMain(str_repeat('a', 64), [new RecordedMerge(str_repeat('c', 64), self::BASE)]);
        $this->expectExceptionObject(new CommitNameNotFull('The recorded merge base "'.self::BASE.'" is not a full commit name of this repository (64 characters).'));

        $main->mustNameFullCommitsOfLength(64);
    }

    public function testASha1LengthRecordedHeadInASha256RepositoryIsRefused(): void
    {
        $main = new WatchedMain(str_repeat('a', 64), [new RecordedMerge(self::HEAD, str_repeat('b', 64))]);
        $this->expectExceptionObject(new CommitNameNotFull('The recorded merge head "'.self::HEAD.'" is not a full commit name of this repository (64 characters).'));

        $main->mustNameFullCommitsOfLength(64);
    }

    public function testASha256LengthSeenHeadInASha1RepositoryIsRefused(): void
    {
        $main = new WatchedMain(str_repeat('a', 64), []);
        $this->expectExceptionObject(new CommitNameNotFull('The last seen head "'.str_repeat('a', 64).'" is not a full commit name of this repository (40 characters).'));

        $main->mustNameFullCommitsOfLength(40);
    }
}
