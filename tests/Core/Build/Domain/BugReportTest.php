<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Build\Domain;

use Intakt\Core\Build\Contract\TaskKind;
use Intakt\Core\Build\Domain\BugPart;
use Intakt\Core\Build\Domain\BugReport;
use Intakt\Core\Build\Domain\BuildQuestion;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(BugReport::class)]
#[CoversClass(BugPart::class)]
#[CoversClass(TaskKind::class)]
#[CoversClass(BuildQuestion::class)]
final class BugReportTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function completeBodies(): iterable
    {
        yield 'bold labels' => ["**When:** open the page\n**Today:** it fails\n**Expected:** it loads\n**Must keep working:** the list"];
        yield 'headings' => ["## When\nopen the page\n\n## Today\nit fails\n\n## Expected\nit loads\n\n## Must keep working\nthe list"];
        yield 'labels with a colon, in a list, in any letter case' => ["- when: open\n- TODAY: fails\n* Expected: loads\n- must keep working: the list"];
        yield 'bold label with the colon outside' => ["**When**: a\n**Today**: b\n**Expected**: c\n**Must keep working**: d"];
    }

    #[DataProvider('completeBodies')]
    public function testABugBodyWithWhenTodayExpectedAndMustKeepWorkingHasNoMissingPart(string $body): void
    {
        self::assertSame([], BugReport::fromBody($body)->missingParts());
    }

    public function testABugBodyWithoutMustKeepWorkingNamesThatPartAsMissing(): void
    {
        $report = BugReport::fromBody("**When:** open\n**Today:** fails\n**Expected:** loads");

        self::assertSame([BugPart::MustKeepWorking], $report->missingParts());
    }

    public function testMissingPartsComeInTheOrderTheTaskStatesThem(): void
    {
        self::assertSame([BugPart::When, BugPart::Expected], BugReport::fromBody("**Today:** fails\n**Must keep working:** the list")->missingParts());
    }

    public function testAPartWithAHeadingButNoTextCountsAsMissing(): void
    {
        $report = BugReport::fromBody("## When\nopen\n## Today\n\n## Expected\nloads\n## Must keep working\n   \n");

        self::assertSame([BugPart::Today, BugPart::MustKeepWorking], $report->missingParts());
    }

    public function testAnotherHeadingEndsAPartSoItsTextDoesNotCountForIt(): void
    {
        $report = BugReport::fromBody("**When:** open\n**Today:** fails\n**Expected:** loads\n**Must keep working:**\n## Acceptance\n- a test");

        self::assertSame([BugPart::MustKeepWorking], $report->missingParts());
    }

    public function testASentenceThatOnlyStartsWithTheWordWhenIsNoPart(): void
    {
        self::assertSame(BugPart::cases(), BugReport::fromBody("When I open the page it fails.\nToday it is slow.\n## Whenever\ntext")->missingParts());
    }

    public function testAnEmptyBodyMissesEveryPart(): void
    {
        self::assertSame(BugPart::cases(), BugReport::fromBody('')->missingParts());
    }

    public function testOnlyTheBugLabelMakesATaskABug(): void
    {
        self::assertSame([TaskKind::Bug, TaskKind::Task, TaskKind::Task], [
            TaskKind::fromLabels(['intakt', 'bug']),
            TaskKind::fromLabels(['intakt', 'bugfix']),
            TaskKind::fromLabels([]),
        ]);
    }

    public function testTheQuestionForMissingPartsNamesThemAndRecommendsAddingThem(): void
    {
        $question = BuildQuestion::forMissingBugParts([BugPart::Today, BugPart::MustKeepWorking]);

        self::assertStringContainsString('missing: Today, Must keep working.', $question->question);
        self::assertSame(['Add the missing parts to the task', 'Remove the bug label'], $question->choices);
        self::assertSame('Add the missing parts to the task', $question->recommendation);
    }
}
