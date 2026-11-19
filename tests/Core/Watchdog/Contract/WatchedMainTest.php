<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Watchdog\Contract;

use Intakt\Core\Watchdog\Contract\CommitName;
use Intakt\Core\Watchdog\Contract\RecordedMerge;
use Intakt\Core\Watchdog\Contract\WatchedMain;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(RecordedMerge::class)]
#[CoversClass(WatchedMain::class)]
#[CoversClass(CommitName::class)]
final class WatchedMainTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function namesThatAreNoFullCommitName(): iterable
    {
        yield 'abbreviated' => ['abc1234'];
        yield 'not hexadecimal' => [str_repeat('g', 40)];
        yield 'upper case' => [str_repeat('A', 40)];
        yield 'an option' => ['--all'.str_repeat('a', 35)];
        yield 'a range' => [str_repeat('a', 40).'..'.str_repeat('b', 40)];
        yield 'a trailing newline' => [str_repeat('a', 40)."\n"];
        yield 'empty' => [''];
    }

    #[DataProvider('namesThatAreNoFullCommitName')]
    public function testARecordedMergeWithAHeadThatIsNoFullCommitNameIsRefused(string $name): void
    {
        $this->expectException(InvalidArgumentException::class);

        new RecordedMerge($name, str_repeat('b', 40));
    }

    #[DataProvider('namesThatAreNoFullCommitName')]
    public function testARecordedMergeWithABaseThatIsNoFullCommitNameIsRefused(string $name): void
    {
        $this->expectException(InvalidArgumentException::class);

        new RecordedMerge(str_repeat('a', 40), $name);
    }

    public function testARecordedMergeAcceptsFullNamesOfBothObjectFormats(): void
    {
        $merge = new RecordedMerge(str_repeat('a', 40), str_repeat('b', 64));

        self::assertSame(str_repeat('a', 40), $merge->head);
        self::assertSame(str_repeat('b', 64), $merge->base);
    }

    public function testAWatchedMainWithALastSeenHeadThatIsNoFullCommitNameIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new WatchedMain('/repository', '--output=x', [], []);
    }

    public function testAWatchedMainWithAnOwnerKeyThatIsNoPublicKeyLineIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new WatchedMain('/repository', null, [], ['justonefield']);
    }
}
