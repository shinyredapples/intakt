<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Memory\Contract;

use Intakt\Core\Git\Contract\Paths;
use Intakt\Core\Memory\Contract\MemoryEntry;
use Intakt\Core\Memory\Contract\RelevantMemory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RelevantMemory::class)]
#[UsesClass(MemoryEntry::class)]
#[UsesClass(Paths::class)]
final class RelevantMemoryTest extends TestCase
{
    public function testEntriesStayInRelevanceOrder(): void
    {
        $memory = RelevantMemory::within([$this->entry('first'), $this->entry('second')], 100);

        self::assertSame(['first', 'second'], array_map(static fn (MemoryEntry $entry): string => $entry->text, $memory->entries));
    }

    public function testEntriesThatFitExactlyAreKept(): void
    {
        $memory = RelevantMemory::within([$this->entry('abc'), $this->entry('def')], 6);

        self::assertCount(2, $memory->entries);
    }

    public function testItStopsBeforeTheEntryThatWouldExceedTheCap(): void
    {
        $memory = RelevantMemory::within([$this->entry('abc'), $this->entry('toolong'), $this->entry('d')], 6);

        self::assertCount(1, $memory->entries);
    }

    public function testTheMostRelevantEntryIsKeptWholeEvenWhenItAloneIsOverTheCap(): void
    {
        $memory = RelevantMemory::within([$this->entry('abcdefgh'), $this->entry('a')], 4);

        self::assertSame(['abcdefgh'], array_map(static fn (MemoryEntry $entry): string => $entry->text, $memory->entries));
    }

    public function testALessRelevantEntryOverTheCapIsNeverReturned(): void
    {
        $memory = RelevantMemory::within([$this->entry('ab'), $this->entry('abcdefgh')], 4);

        self::assertSame(['ab'], array_map(static fn (MemoryEntry $entry): string => $entry->text, $memory->entries));
    }

    public function testNoEntriesGiveNoMemory(): void
    {
        self::assertSame([], RelevantMemory::within([], 4)->entries);
    }

    public function testTheCapCountsBytesNotCharacters(): void
    {
        $memory = RelevantMemory::within([$this->entry('a'), $this->entry('éé')], 4);

        self::assertCount(1, $memory->entries);
    }

    private function entry(string $text): MemoryEntry
    {
        return new MemoryEntry('alpha', $text, new Paths('a.php'));
    }
}
