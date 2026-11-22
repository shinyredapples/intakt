<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Contract;

use Intakt\Core\Review\Contract\JudgeNotes;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(JudgeNotes::class)]
final class JudgeNotesTest extends TestCase
{
    public function testANoteAddedForAFindingIsReadBackByItsId(): void
    {
        self::assertSame('Not reachable.', new JudgeNotes()->with('F1', 'Not reachable.')->noteOf('F1'));
    }

    public function testAFindingWithoutANoteReadsAsNull(): void
    {
        self::assertNull(new JudgeNotes()->with('F1', 'Not reachable.')->noteOf('F2'));
    }

    public function testANoteWithoutAFindingIdIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new JudgeNotes()->with('', 'Not reachable.');
    }

    public function testAnEmptyNoteIsKeptAsTheJudgeGaveIt(): void
    {
        self::assertSame('', new JudgeNotes()->with('F1', '')->noteOf('F1'));
    }

    public function testANoteKeepsItsWhitespaceAsGiven(): void
    {
        self::assertSame("  Not reachable.\n", new JudgeNotes()->with('F1', "  Not reachable.\n")->noteOf('F1'));
    }

    public function testANumericFindingIdIteratesBackAsAStringKey(): void
    {
        $keys = [];
        foreach (new JudgeNotes()->with('7', 'Not reachable.') as $finding => $note) {
            $keys[] = $finding;
        }

        self::assertSame(['7'], $keys);
    }

    public function testNotesIterateInTheOrderTheyWereAdded(): void
    {
        self::assertSame(['F2' => 'Second.', 'F1' => 'First.'], iterator_to_array(new JudgeNotes()->with('F2', 'Second.')->with('F1', 'First.')));
    }

    public function testAddingANoteLeavesTheOriginalNotesUnchanged(): void
    {
        $notes = new JudgeNotes();
        $notes->with('F1', 'Not reachable.');

        self::assertSame([], iterator_to_array($notes));
    }
}
