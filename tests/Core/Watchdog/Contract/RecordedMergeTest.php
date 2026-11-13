<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Watchdog\Contract;

use Intakt\Core\Watchdog\Contract\RecordedMerge;
use Intakt\Core\Watchdog\Contract\WatchedMain;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RecordedMerge::class)]
#[UsesClass(WatchedMain::class)]
final class RecordedMergeTest extends TestCase
{
    public function testARangeOfTwoFullCommitNamesNamesTheBaseAndTheHead(): void
    {
        $merge = RecordedMerge::fromRange(str_repeat('a', 40).'..'.str_repeat('b', 40));

        self::assertSame([str_repeat('a', 40), str_repeat('b', 40)], [$merge->base, $merge->head]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function rangesThatAreNotTwoFullCommitNames(): iterable
    {
        yield 'a head alone' => [str_repeat('b', 40)];
        yield 'an abbreviated base' => ['abc123..'.str_repeat('b', 40)];
        yield 'an option as head' => [str_repeat('a', 40).'..--all'];
        yield 'an empty head' => [str_repeat('a', 40).'..'];
        yield 'a symmetric difference' => [str_repeat('a', 40).'...'.str_repeat('b', 40)];
        yield 'three parts' => [str_repeat('a', 40).'..'.str_repeat('b', 40).'..'.str_repeat('c', 40)];
    }

    #[DataProvider('rangesThatAreNotTwoFullCommitNames')]
    public function testARangeThatIsNotTwoFullCommitNamesIsRefused(string $range): void
    {
        $this->expectException(InvalidArgumentException::class);

        RecordedMerge::fromRange($range);
    }
}
