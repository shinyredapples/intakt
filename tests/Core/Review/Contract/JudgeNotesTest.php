<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Contract;

use Intakt\Core\Review\Contract\JudgeNotes;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(JudgeNotes::class)]
final class JudgeNotesTest extends TestCase
{
    public function testANotedFindingGivesItsNote(): void
    {
        self::assertSame('Not reachable.', new JudgeNotes()->with('F1', 'Not reachable.')->of('F1'));
    }

    public function testAFindingWithoutANoteGivesNone(): void
    {
        self::assertNull(new JudgeNotes()->with('F1', 'Not reachable.')->of('F2'));
    }

    public function testAddingANoteLeavesTheOriginalUnchanged(): void
    {
        $none = new JudgeNotes();

        $none->with('F1', 'Not reachable.');

        self::assertSame([], iterator_to_array($none));
    }

    public function testTheNotesAreIteratedInTheOrderTheyWereAdded(): void
    {
        self::assertSame(['F2' => 'Later.', 'F1' => 'Not reachable.'], iterator_to_array(new JudgeNotes()->with('F2', 'Later.')->with('F1', 'Not reachable.')));
    }

    public function testANumericFindingIdIsIteratedAsAString(): void
    {
        $notes = [];

        foreach (new JudgeNotes()->with('7', 'Later.') as $finding => $note) {
            $notes[] = [$finding, $note];
        }

        self::assertSame([['7', 'Later.']], $notes);
    }
}
