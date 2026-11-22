<?php

declare(strict_types=1);

namespace Intakt\Tests\Application\Parity;

use Intakt\Tests\Application\Parity\Fixtures\AbstractCandidate;
use Intakt\Tests\Application\Parity\Fixtures\PlainCandidate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

#[CoversClass(ParityRecord::class)]
#[CoversClass(IntaktTestNames::class)]
#[CoversClass(TestClassIndex::class)]
final class ParityRecordTest extends TestCase
{
    private const string NOT_BUILT_FORM = 'an open item must say after `Intakt:` what is not built yet, as `not built yet (<what>, T<n>)` or `not built yet: <what>, T<n>`';

    private const string NOT_BUILT_FORM_AT_LINE_2 = 'line 2: '.self::NOT_BUILT_FORM;

    /** @var list<string> */
    private array $scratches = [];

    public function testEveryDoneParityItemNamesAnIntaktTestThatExists(): void
    {
        $root = dirname(__DIR__, 3);
        $record = (string) file_get_contents($root.'/docs/architecture/parity.md');

        self::assertSame([], $this->repositoryTests()->unprovenItems($record));
    }

    public function testEveryJudgedSectionIsAHeadingOfTheRecord(): void
    {
        $record = (string) file_get_contents(dirname(__DIR__, 3).'/docs/architecture/parity.md');

        foreach (ParityRecord::JUDGED_SECTIONS as $section) {
            self::assertStringContainsString("\n## ".$section."\n", $record);
        }
    }

    public function testEverySectionHeadingOfTheRecordIsAJudgedSection(): void
    {
        $record = (string) file_get_contents(dirname(__DIR__, 3).'/docs/architecture/parity.md');

        self::assertSame(ParityRecord::JUDGED_SECTIONS, $this->repositoryTests()->sectionHeadings($record));
    }

    public function testTheOpenItemsOfTheJudgedSectionsAreTheBehaviorsNotBuiltYet(): void
    {
        self::assertSame([
            'Intake and shaping, line 10: A task waits until every `Waits for #n` issue is closed; a line naming the task\'s own pull request is no wait, and a pull request target counts as the task it closes for that task\'s failure and stall, but releases only on that pull request\'s confirmed merge into main.',
            'Intake and shaping, line 11: Open pull request work goes before new tasks; only urgent goes before it.',
            'Intake and shaping, line 12: Projects get free slots in share order; urgent work first; a project at share 0 is paused for builds and reviews.',
            'Intake and shaping, line 13: A task split creates its parts in order with `Waits for` lines, and the original becomes an umbrella.',
            'Intake and shaping, line 14: A task is never split twice; an interrupted split ends on the first part once.',
            'Intake and shaping, line 15: An umbrella closes only on trusted notes when its parts close.',
            'Build and hand-back, line 20: Builder and reviewer egress is limited to an allowlist; the vendor login is unreadable to the model\'s commands.',
            'Build and hand-back, line 22: Builds run the project\'s own PHP, never a fallback.',
            'Build and hand-back, line 28: Only a green check pushes; the pre-check in the session catches refusals first.',
            'Build and hand-back, line 29: Every Evidence item is referenced; a miss goes back once, a second miss goes to the reviewers.',
            'Build and hand-back, line 30: A body over the cap goes back once with its commits kept.',
            'Build and hand-back, line 31: Composer changes beyond routine go to the owner, naming the packages.',
            'Build and hand-back, line 35: Transient forge API network failures are retried; one that outlasts the retries makes that project wait, not halt; only a long outage halts and notifies.',
            'CI, line 39: A head with pending CI holds nothing; heads with finished CI go first.',
            'CI, line 40: Red CI goes back before any reviewer and uses no cycle; the third bounce in a row counts.',
            'CI, line 41: The CI excerpt is redacted, fenced and capped.',
            'CI, line 42: A missed CI start is nudged once; a pull request the forge reports in conflict with main is not nudged but goes to Intakt\'s checked registry-file merge, or to a builder as a merge-main rework; an unreadable workflow fails closed.',
            'Reviews, line 45: An approval counts only for the exact head and pull request text.',
            'Reviews, line 48: Re-reviews are scoped from saved reviews; a saved approval never counts for a new head.',
            'Reviews, line 49: Feedback holds only open findings, merged across reviewers.',
            'Reviews, line 50: An unchanged head with an outside requirement goes on hold, not to review.',
            'Reviews, line 51: Evidence only the owner can give reaches status without a send-back.',
            'Reviews, line 53: Probes run in a no-network sandbox with a fixed command surface; infrastructure failures alert, never become findings.',
            'Reviews, line 54: An out-of-limit reviewer gets a same-tier substitute whose verdict counts, and a catch-up is owed.',
            'Reviews, line 55: A rejection beside an out-of-limit reviewer goes to the builder without a substitute.',
            'Reviews, line 56: A crash during a catch-up neither loses nor doubles it.',
            'Reviews, line 57: A P1 on two heads is escalated to the strong tier; its verdict is reused on unchanged files.',
            'Planning and budget, line 60: A start is refused only by measured use at its ceiling, never by an estimate.',
            'Planning and budget, line 62: A usage probe outage gives one notice.',
            'Planning and budget, line 63: A budget back-off and a pause still merge fully approved heads; a drain and a crash back-off stop merges too.',
            'Planning and budget, line 65: The owner\'s ceiling override expires and keeps a crash back-off.',
            'Planning and budget, line 66: The cheaper tier starts; a label, a sensitive path or an earlier failure starts strong; a P1 raises the next rework.',
            'Planning and budget, line 67: No progress for the set time stops a build with its commits kept; the runaway cap stops it regardless.',
            'Merge and protection, line 70: The merge is a fast-forward of exactly the reviewed head; main moving after the checks fails it; a lost push response stays covered.',
            'Merge and protection, line 71: A crash after publishing, closing or cleaning up never publishes twice.',
            'Merge and protection, line 74: Only the owner\'s approval of exactly this head counts; Intakt never submits a review.',
            'Merge and protection, line 75: A head waiting for the owner holds no build slot; an ordinary change never waits.',
            'Merge and protection, line 76: At the owner-wait cap a head is queued and asked once a place frees.',
            'Merge and protection, line 77: A conflict after the owner\'s approval parks it; an unchanged resolution merges without a new ask.',
            'Merge and protection, line 78: Owner and maintainer pull requests are reviewed on every head and merge like a task\'s.',
            'Merge and protection, line 79: A third party\'s priority label on a pull request is ignored.',
            'Merge and protection, line 80: Splitting is not only for tasks: a head past the split tier on a pull request with no task goes to the maintainer agent as a split when it was opened by the owner\'s or the maintainer agent\'s identity (with a task, the maintainer agent splits the task).',
            'Merge and protection, line 81: A pull request split records its plan first (resumable, no part created twice); each part is a task naming `Source: <branch>@<sha>` of the original head, so the builder applies the source\'s own diff and writes nothing new, cut along files where possible, each under the split tier, stating its merge order when it links to another part.',
            'Merge and protection, line 82: Once every part\'s pull request is open or merged (a part closed without merging counts as missing) and plain code has reconstructed the original change twice, first from the heads recorded when the part pull requests opened (the parts, applied in merge order to the merge base, give the tree of `<sha>`), then from each open part\'s current head and each merged part\'s merged change, which must carry exactly its share (nothing dropped, nothing added), Intakt closes the original with a comment naming the parts; on a missing part or a mismatch the original stays open.',
            'Merge and protection, line 83: Anyone else\'s oversized pull request is not split: it gets one comment saying it is past the size limit and asking the author to split it; once a person with write access sets `take-over`, it is split like the owner\'s.',
            'Merge and protection, line 84: Main is watched by how a commit arrived, never by its signature.',
            'Operations, line 87: Every non-merge outcome records a cause.',
            'Operations, line 88: Every notice has a kind; only owner actions push; a failed push is reported, not raised.',
            'Operations, line 89: A crash between a step\'s end and its hand-over only completes the hand-over.',
            'Operations, line 90: Status shows pause, drain, parked items and steps.',
            'Operations, line 91: Drain stops build admission on every slot while the review lane runs; unlike the reference, drain wins over pause, because a drain also stops merges and the strictest hold decides.',
            'Operations, line 92: A crash in one build slot is recovered without touching another slot\'s step.',
            'Operations, line 93: Each project\'s prompts and posted text name only that project.',
        ], array_keys(self::notBuiltItemsOfTheRecord()));
    }

    /**
     * @param list<string> $tasks
     */
    #[DataProvider('behaviorsNotBuiltYet')]
    public function testEachBehaviorNotBuiltYetIsReportedByNameWithThePlanTaskThatBuildsIt(array $tasks): void
    {
        $plan = (string) file_get_contents(dirname(__DIR__, 3).'/docs/plan.md');

        foreach ($tasks as $task) {
            self::assertStringContainsString("\n### ".$task.' ', $plan);
        }
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function behaviorsNotBuiltYet(): iterable
    {
        foreach (self::notBuiltItemsOfTheRecord() as $behavior => $tasks) {
            yield $behavior => [$tasks];
        }
    }

    public function testTwoOpenItemsWithTheSameFirstSentenceAreBothListed(): void
    {
        $record = "## CI\n- [ ] A behavior. One. [T1]; Intakt: not built yet (T1)\n- [ ] A behavior. Two. [T2]; Intakt: not built yet (T2)";

        self::assertSame(
            ['CI, line 2: A behavior.' => ['T1'], 'CI, line 3: A behavior.' => ['T2']],
            $this->repositoryTests()->notBuiltItems($record),
        );
    }

    public function testAMarkerNamingTwoTasksListsBoth(): void
    {
        $record = "## CI\n- [ ] A behavior. [T1]; Intakt: not built yet (the lanes, T56 and T57)";

        self::assertSame(['CI, line 2: A behavior.' => ['T56', 'T57']], $this->repositoryTests()->notBuiltItems($record));
    }

    public function testAStarBulletOpenItemInAJudgedSectionIsReported(): void
    {
        $record = "## CI\n* [ ] A behavior. [T1]; Intakt: not built yet (T1)";

        self::assertSame(
            ['line 2: a list item of a judged section must start with `- [ ]` or `- [x]`'],
            $this->repositoryTests()->unprovenItems($record),
        );
    }

    #[DataProvider('listItemsWithoutTheExactCheckbox')]
    public function testAListItemWithoutTheExactCheckboxInAJudgedSectionIsReported(string $item): void
    {
        self::assertSame(
            ['line 2: a list item of a judged section must start with `- [ ]` or `- [x]`'],
            $this->repositoryTests()->unprovenItems("## CI\n".$item),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function listItemsWithoutTheExactCheckbox(): iterable
    {
        yield 'a plain bullet' => ['- A behavior. `test_old.py` [T1]'];
        yield 'a plus bullet with a checkbox' => ['+ [x] A behavior. [T1]; Intakt: `ParityRecordTest::testAnIndentedDoneItemIsJudged`'];
        yield 'a numbered item' => ['1. [ ] A behavior. [T1]; Intakt: not built yet (T1)'];
        yield 'a bullet without text' => ['-'];
    }

    public function testAStarBulletOutsideTheJudgedSectionsIsNotReported(): void
    {
        $record = "## Notes\n* [ ] A behavior. `test_old.py` [T1]\n- A note.";

        self::assertSame([], $this->repositoryTests()->unprovenItems($record));
    }

    #[DataProvider('statusesThatAreNoNotBuiltMarker')]
    public function testAnOpenItemWhoseStatusOnlyContainsTheWordsNotBuiltYetIsReported(string $status): void
    {
        self::assertSame(
            [self::NOT_BUILT_FORM_AT_LINE_2],
            $this->repositoryTests()->unprovenItems("## CI\n- [ ] A behavior. [T1]; Intakt: ".$status),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function statusesThatAreNoNotBuiltMarker(): iterable
    {
        yield 'a sentence denying it' => ['It is no longer true that this is not built yet.'];
        yield 'a question' => ['(it is not built yet? no, it is)'];
        yield 'a marker without its task' => ['not built yet (the rest)'];
        yield 'a task only after the remark closed' => ['not built yet (the rest) T1'];
        yield 'the words inside another word' => ['`ParityRecordTest::testAnIndentedDoneItemIsJudged` (cannot built yet: T1)'];
        yield 'a marker that is never closed' => ['not built yet (the rest, T1'];
        yield 'a remark after the marker that says it is built' => ['not built yet (the rest, T1) was the old status; it is now built.'];
        yield 'a remark after the colon form' => ['`ParityRecordTest::testAnIndentedDoneItemIsJudged` (not built yet: the rest, T1) but now it is'];
        yield 'a parenthesis inside what is not built' => ['not built yet (the rest (most of it), T1)'];
        yield 'a colon marker in a remark that is never closed' => ['`ParityRecordTest::testAnIndentedDoneItemIsJudged` (built; not built yet: the rest, T57'];
        yield 'a colon marker right after a parenthesis that is never closed' => ['`ParityRecordTest::testAnIndentedDoneItemIsJudged` (not built yet: the rest, T1'];
        yield 'a colon marker with a stray closing parenthesis' => ['not built yet: the rest, T1)'];
        yield 'a parenthesis marker with a stray closing parenthesis' => ['not built yet (the rest, T1))'];
        yield 'a marker after an earlier remark that is never closed' => ['`ParityRecordTest::testAnIndentedDoneItemIsJudged` (built (most; not built yet: the rest, T1)'];
        yield 'a marker after a semicolon outside any remark' => ['built; not built yet: the rest, T1'];
        yield 'a marker after a link whose target is never closed' => ['(see [the record](parity(test.md; not built yet: the rest, T1)'];
    }

    #[DataProvider('statusesThatAreNotBuiltMarkers')]
    public function testAnOpenItemWithAClosedNotBuiltMarkerIsAccepted(string $status): void
    {
        self::assertSame([], $this->repositoryTests()->unprovenItems("## CI\n- [ ] A behavior. [T1]; Intakt: ".$status));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function statusesThatAreNotBuiltMarkers(): iterable
    {
        yield 'the parenthesis form at the start' => ['not built yet (the rest, T1)'];
        yield 'the colon form at the start' => ['not built yet: the rest, T1'];
        yield 'the colon form right after a parenthesis it closes' => ['`ParityRecordTest::testAnIndentedDoneItemIsJudged` (not built yet: the rest, T1)'];
        yield 'the parenthesis form right after a parenthesis it closes' => ['`ParityRecordTest::testAnIndentedDoneItemIsJudged` (not built yet (the rest, T1))'];
        yield 'the colon form after a semicolon in a remark it closes' => ['`ParityRecordTest::testAnIndentedDoneItemIsJudged` (built; not built yet: the rest, T1)'];
        yield 'a remark that holds a link before the marker' => ['`ParityRecordTest::testAnIndentedDoneItemIsJudged` (see [the record](parity.md); not built yet: the rest, T1)'];
        yield 'a remark that holds a link with parentheses in its target before the marker' => ['(see [the record](parity(test).md); not built yet: the rest, T1)'];
        yield 'a link with parentheses in its target before the remark' => ['[the record](parity(test).md) (not built yet: the rest, T1)'];
    }

    public function testAMarkerJustBeforeTheLineEndWithTrailingSpacesIsAccepted(): void
    {
        $record = "## CI\n- [ ] A behavior. [T1]; Intakt: not built yet: the rest, T1  ";

        self::assertSame([], $this->repositoryTests()->unprovenItems($record));
    }

    public function testAnOpenItemWhoseMarkerNamesItsTaskIsListedWithThatTask(): void
    {
        $record = "## CI\n- [ ] A behavior. [T1]; Intakt: `ParityRecordTest::testAnIndentedDoneItemIsJudged` (built; not built yet: the rest, T57)";

        self::assertSame(['CI, line 2: A behavior.' => ['T57']], $this->repositoryTests()->notBuiltItems($record));
    }

    public function testAMarkerFollowedByAMissingTestReportsBothTheTestAndTheMarker(): void
    {
        $record = "## CI\n- [ ] A behavior. [T1]; Intakt: not built yet (T1); `NoSuchParityTest::testAnything`";

        self::assertSame(
            ['line 2: NoSuchParityTest is no test class under tests/', self::NOT_BUILT_FORM_AT_LINE_2],
            $this->repositoryTests()->unprovenItems($record),
        );
    }

    public function testAnOpenItemOfAJudgedSectionThatSaysNothingAfterIntaktIsReportedWithItsLine(): void
    {
        $record = "## CI\n- [ ] A behavior. `test_old.py` [T1]";

        self::assertSame(
            [self::NOT_BUILT_FORM_AT_LINE_2],
            $this->repositoryTests()->unprovenItems($record),
        );
    }

    public function testAnOpenItemOfAJudgedSectionNamingOnlyExistingTestsWithoutSayingWhatIsNotBuiltIsReported(): void
    {
        $record = "## Build and hand-back\n- [ ] A behavior. [T1]; Intakt: `ParityRecordTest::testAnIndentedDoneItemIsJudged`";

        self::assertSame(
            [self::NOT_BUILT_FORM_AT_LINE_2],
            $this->repositoryTests()->unprovenItems($record),
        );
    }

    public function testAnOpenItemOfAJudgedSectionThatSaysNotBuiltYetOnlyBeforeIntaktIsReported(): void
    {
        $record = "## CI\n- [ ] A behavior, not built yet. [T1]; Intakt: `ParityRecordTest::testAnIndentedDoneItemIsJudged`";

        self::assertSame(
            [self::NOT_BUILT_FORM_AT_LINE_2],
            $this->repositoryTests()->unprovenItems($record),
        );
    }

    public function testAnOpenItemOfAJudgedSectionNamingAMissingTestIsReported(): void
    {
        $record = "## Intake and shaping\n- [ ] A behavior. [T1]; Intakt: `NoSuchParityTest::testAnything` (not built yet: the rest, T1)";

        self::assertSame(
            ['line 2: NoSuchParityTest is no test class under tests/'],
            $this->repositoryTests()->unprovenItems($record),
        );
    }

    public function testAnOpenItemOfAJudgedSectionThatSaysWhatIsNotBuiltYetPasses(): void
    {
        $record = "## CI\n- [ ] A behavior. [T1]; Intakt: `ParityRecordTest::testAnIndentedDoneItemIsJudged` (not built yet: the rest, T1)\n- [ ] Another. [T2]; Intakt: not built yet (T2)";

        self::assertSame([], $this->repositoryTests()->unprovenItems($record));
    }

    public function testAnOpenItemOfAJudgedSectionThatSaysWhatIsNotBuiltYetIsListedByItsSectionAndFirstSentence(): void
    {
        $record = "## CI\n- [ ] A behavior. Done later. [T1]; Intakt: not built yet (T1)";

        self::assertSame(['CI, line 2: A behavior.' => ['T1']], $this->repositoryTests()->notBuiltItems($record));
    }

    public function testADoneItemOfAJudgedSectionIsNotListedAsNotBuiltThoughItsRemarkNamesAMissingPart(): void
    {
        $record = '## CI'."\n".'- [x] A behavior. [T1]; Intakt: `ParityRecordTest::testAnIndentedDoneItemIsJudged` (not built yet: the lane, T2)';

        self::assertSame([], $this->repositoryTests()->notBuiltItems($record));
    }

    public function testEverySectionHeadingOfAnyLevelOrSeparatorIsFoundSoNoneHidesAnItem(): void
    {
        $record = "# Title\n## One\n# Two\n##\tThree\n### Deeper\n";

        self::assertSame(['One', 'Two', 'Three'], $this->repositoryTests()->sectionHeadings($record));
    }

    public function testAnOpenItemOutsideTheJudgedSectionsIsNeitherJudgedNorListed(): void
    {
        $record = "## CI\n- [ ] A behavior. [T1]; Intakt: not built yet (T1)\n## Notes\n- [ ] Another. `test_old.py` [T2]; Intakt: `NoSuchParityTest::testAnything`\n  and more words";
        $parity = $this->repositoryTests();

        self::assertSame([], $parity->unprovenItems($record));
        self::assertSame(['CI, line 2: A behavior.' => ['T1']], $parity->notBuiltItems($record));
    }

    public function testAHeadingThatOnlyStartsLikeAJudgedSectionNameDoesNotTurnOnJudging(): void
    {
        $record = "## CI later\n- [ ] A behavior. `test_old.py` [T1]";

        self::assertSame([], $this->repositoryTests()->unprovenItems($record));
    }

    public function testADeeperHeadingInsideAJudgedSectionKeepsItJudged(): void
    {
        $record = "## CI\n### Details\n- [ ] A behavior. `test_old.py` [T1]";

        self::assertSame(
            ['line 3: '.self::NOT_BUILT_FORM],
            $this->repositoryTests()->unprovenItems($record),
        );
    }

    public function testATopLevelHeadingEndsAJudgedSection(): void
    {
        $record = "## CI\n# Appendix\n- [ ] A behavior. `test_old.py` [T1]";

        self::assertSame([], $this->repositoryTests()->unprovenItems($record));
    }

    public function testANonBlankLineDirectlyAfterAnOpenItemOfAJudgedSectionIsReported(): void
    {
        $record = "## CI\n- [ ] A behavior. [T1]; Intakt: not built yet (T1)\n  and `NoSuchParityTest::testAnything`";

        self::assertSame(
            ['line 3: an open item of a judged section must stay on one line'],
            $this->repositoryTests()->unprovenItems($record),
        );
    }

    public function testADoneItemNamingAnExistingTestMethodPasses(): void
    {
        $record = '- [x] A behavior. `test_old.py::test_it` [T1]; Intakt: `ParityRecordTest::testADoneItemNamingAnExistingTestMethodPasses`';

        self::assertSame([], $this->repositoryTests()->unprovenItems($record));
    }

    public function testADoneItemWhoseTestMethodDoesNotExistIsReportedWithItsLine(): void
    {
        $record = "## Section\n- [x] A behavior. `test_old.py` [T1]; Intakt: `ParityRecordTest::testNothingLikeThis`";

        self::assertSame(
            ['line 2: ParityRecordTest::testNothingLikeThis is no test method of '.self::class],
            $this->repositoryTests()->unprovenItems($record),
        );
    }

    public function testADoneItemWhoseTestClassDoesNotExistIsReported(): void
    {
        $record = '- [x] A behavior. `test_old.py` [T1]; Intakt: `NoSuchParityTest::testAnything`';

        self::assertSame(
            ['line 1: NoSuchParityTest is no test class under tests/'],
            $this->repositoryTests()->unprovenItems($record),
        );
    }

    public function testADoneItemNamingOnlyTheExistingLoopsTestIsReported(): void
    {
        $record = '- [x] A behavior. `test_old.py::test_it_works` [T1]';

        self::assertSame(
            ['line 1: names no Intakt test after `Intakt:`'],
            $this->repositoryTests()->unprovenItems($record),
        );
    }

    public function testABareMethodAfterAClassIsCheckedOnThatClass(): void
    {
        $record = '- [x] A behavior. [T1]; Intakt: `ParityRecordTest::testADoneItemNamingAnExistingTestMethodPasses` and `testNothingLikeThis`';

        self::assertSame(
            ['line 1: ParityRecordTest::testNothingLikeThis is no test method of '.self::class],
            $this->repositoryTests()->unprovenItems($record),
        );
    }

    public function testABareMethodBeforeAnyClassIsReported(): void
    {
        $record = '- [x] A behavior. [T1]; Intakt: `testADoneItemNamingAnExistingTestMethodPasses`';

        self::assertSame(
            ['line 1: `testADoneItemNamingAnExistingTestMethodPasses` follows no test class'],
            $this->repositoryTests()->unprovenItems($record),
        );
    }

    public function testAnUncheckedItemNamingAMissingTestIsNotJudged(): void
    {
        $record = '- [ ] A behavior. `test_old.py` [T1]; Intakt: `NoSuchParityTest::testAnything`';

        self::assertSame([], $this->repositoryTests()->unprovenItems($record));
    }

    public function testAnItemCheckedWithACapitalXIsJudged(): void
    {
        $record = '- [X] A behavior. `test_old.py` [T1]';

        self::assertSame(
            ['line 1: names no Intakt test after `Intakt:`'],
            $this->repositoryTests()->unprovenItems($record),
        );
    }

    public function testABacktickedCommandOrClassInARemarkIsNotReadAsATestName(): void
    {
        $record = '- [x] A behavior. [T1]; Intakt: `ParityRecordTest::testADoneItemNamingAnExistingTestMethodPasses` (`intakt:build:run` runs the `FencedPlants` gate)';

        self::assertSame([], $this->repositoryTests()->unprovenItems($record));
    }

    public function testAClassNameThatTwoTestFilesShareIsReported(): void
    {
        $tests = $this->scratchTests(['A/TwinTest.php', 'B/TwinTest.php']);

        self::assertSame(
            ['line 1: TwinTest names more than one test class: Intakt\Tests\Scratch\A\TwinTest, Intakt\Tests\Scratch\B\TwinTest'],
            $tests->unprovenItems('- [x] A behavior. [T1]; Intakt: `TwinTest::testIt`'),
        );
    }

    public function testTheTwoClassesOfASharedNameAreReportedInTheSameOrderWhateverTheFolderOrder(): void
    {
        $first = $this->scratchTests(['A/TwinTest.php', 'B/TwinTest.php'])->unprovenItems('- [x] Item. Intakt: `TwinTest::testIt`');
        $second = $this->scratchTests(['B/TwinTest.php', 'A/TwinTest.php'])->unprovenItems('- [x] Item. Intakt: `TwinTest::testIt`');

        self::assertSame($first, $second);
    }

    public function testAMisspelledClassAfterAValidNameIsReported(): void
    {
        $record = '- [x] Item. Intakt: `ParityRecordTest::testADoneItemNamingAnExistingTestMethodPasses` and `ParityRecordTes::testNothing`';

        self::assertSame(
            ['line 1: `ParityRecordTes::testNothing` is not a test name of the form `SomeTest::testName`'],
            $this->repositoryTests()->unprovenItems($record),
        );
    }

    public function testAMisspelledMethodPrefixAfterAValidNameIsReported(): void
    {
        $record = '- [x] Item. Intakt: `ParityRecordTest::testADoneItemNamingAnExistingTestMethodPasses` and `ParityRecordTest::tesNothing`';

        self::assertSame(
            ['line 1: `ParityRecordTest::tesNothing` is not a test name of the form `SomeTest::testName`'],
            $this->repositoryTests()->unprovenItems($record),
        );
    }

    public function testAMethodNameWithParenthesesAfterAValidNameIsReported(): void
    {
        $record = '- [x] Item. Intakt: `ParityRecordTest::testADoneItemNamingAnExistingTestMethodPasses` and `ParityRecordTest::testNothing()`';

        self::assertSame(
            ['line 1: `ParityRecordTest::testNothing()` is not a test name of the form `SomeTest::testName`'],
            $this->repositoryTests()->unprovenItems($record),
        );
    }

    public function testANonPublicMethodIsNoTestMethod(): void
    {
        $record = '- [x] Item. Intakt: `ParityRecordTest::testAProtectedHelperIsNoTest`';

        self::assertSame(
            ['line 1: ParityRecordTest::testAProtectedHelperIsNoTest is no test method of '.self::class],
            $this->repositoryTests()->unprovenItems($record),
        );
    }

    public function testAnAbstractTestClassIsNoRunnableTestClass(): void
    {
        $record = '- [x] Item. Intakt: `AbstractCandidateTest::testVisible`';

        self::assertSame(
            ['line 1: AbstractCandidateTest is no runnable test class'],
            $this->scratchTestsAliasing(AbstractCandidate::class, 'AbstractCandidateTest')->unprovenItems($record),
        );
    }

    public function testAClassThatIsNoPhpunitTestCaseIsNoRunnableTestClass(): void
    {
        $record = '- [x] Item. Intakt: `PlainCandidateTest::testVisible`';

        self::assertSame(
            ['line 1: PlainCandidateTest is no runnable test class'],
            $this->scratchTestsAliasing(PlainCandidate::class, 'PlainCandidateTest')->unprovenItems($record),
        );
    }

    public function testAMissingNameInADoubleBacktickSpanAfterAValidNameIsReported(): void
    {
        $record = '- [x] Item. Intakt: `ParityRecordTest::testAnIndentedDoneItemIsJudged` and ``ParityRecordTest::testNothing``';

        self::assertSame(
            ['line 1: ParityRecordTest::testNothing is no test method of '.self::class],
            $this->repositoryTests()->unprovenItems($record),
        );
    }

    public function testAMissingNameAfterAnEscapedBacktickOnADoneItemIsReported(): void
    {
        $record = '- [x] Item. Intakt: `ParityRecordTest::testAnIndentedDoneItemIsJudged` (literal \` example) and `ParityRecordTest::testNothing`';

        self::assertSame(
            ['line 1: ParityRecordTest::testNothing is no test method of '.self::class],
            $this->repositoryTests()->unprovenItems($record),
        );
    }

    public function testAnEscapedBacktickBesideValidNamesIsNotReported(): void
    {
        $record = '- [x] Item. Intakt: `ParityRecordTest::testAnIndentedDoneItemIsJudged` (literal \` example) and `testABareMethodAfterAClassIsCheckedOnThatClass`';

        self::assertSame([], $this->repositoryTests()->unprovenItems($record));
    }

    public function testANameWithASingleColonAfterAValidNameIsReportedAsMalformed(): void
    {
        $record = '- [x] Item. Intakt: `ParityRecordTest::testAnIndentedDoneItemIsJudged` and `ParityRecordTest:testNothing`';

        self::assertSame(
            ['line 1: `ParityRecordTest:testNothing` is not a test name of the form `SomeTest::testName`'],
            $this->repositoryTests()->unprovenItems($record),
        );
    }

    public function testANameWithAnArrowAfterAValidNameIsReportedAsMalformed(): void
    {
        $record = '- [x] Item. Intakt: `ParityRecordTest::testAnIndentedDoneItemIsJudged` and `ParityRecordTest->testNothing`';

        self::assertSame(
            ['line 1: `ParityRecordTest->testNothing` is not a test name of the form `SomeTest::testName`'],
            $this->repositoryTests()->unprovenItems($record),
        );
    }

    public function testANameWithADotAfterAValidNameIsReportedAsMalformed(): void
    {
        $record = '- [x] Item. Intakt: `ParityRecordTest::testAnIndentedDoneItemIsJudged` and `ParityRecordTest.testNothing`';

        self::assertSame(
            ['line 1: `ParityRecordTest.testNothing` is not a test name of the form `SomeTest::testName`'],
            $this->repositoryTests()->unprovenItems($record),
        );
    }

    public function testAWordThatOnlyContainsTestIsNoNearMiss(): void
    {
        $record = '- [x] Item. Intakt: `ParityRecordTest::testAnIndentedDoneItemIsJudged` (see `attest.Contest`, `Testing::run` and `latest`)';

        self::assertSame([], $this->repositoryTests()->unprovenItems($record));
    }

    public function testADoneItemWithAnUnclosedBacktickAfterIntaktIsReported(): void
    {
        $record = '- [x] Item. Intakt: `ParityRecordTest::testAnIndentedDoneItemIsJudged` and `ParityRecordTest::testNothing';

        self::assertSame(
            ['line 1: has a backtick after `Intakt:` that is never closed'],
            $this->repositoryTests()->unprovenItems($record),
        );
    }

    public function testANonBlankLineDirectlyAfterADoneItemIsReportedAsADoneItemOverMoreThanOneLine(): void
    {
        $record = "- [x] Item. Intakt: `ParityRecordTest::testAnIndentedDoneItemIsJudged`\n  and `ParityRecordTest::testNothing`";

        self::assertSame(
            ['line 2: a done item must stay on one line'],
            $this->repositoryTests()->unprovenItems($record),
        );
    }

    #[DataProvider('linesStartingWithAHashThatAreNoHeading')]
    public function testALineStartingWithAHashThatIsNoHeadingDirectlyAfterADoneItemIsReported(string $line): void
    {
        $record = "- [x] Item. Intakt: `ParityRecordTest::testAnIndentedDoneItemIsJudged`\n".$line;

        self::assertSame(
            ['line 2: a done item must stay on one line'],
            $this->repositoryTests()->unprovenItems($record),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function linesStartingWithAHashThatAreNoHeading(): iterable
    {
        yield 'an indented issue number' => ['  #3101 also covers `ParityRecordTest::testNothing`'];
        yield 'an unindented issue number' => ['#3101 also covers `ParityRecordTest::testNothing`'];
        yield 'a word after the hash' => ['  #also `ParityRecordTest::testNothing`'];
        yield 'seven hashes' => ['####### `ParityRecordTest::testNothing`'];
    }

    public function testAHeadingWithoutTextDirectlyAfterADoneItemIsNoContinuation(): void
    {
        $record = "- [x] Item. Intakt: `ParityRecordTest::testAnIndentedDoneItemIsJudged`\n##";

        self::assertSame([], $this->repositoryTests()->unprovenItems($record));
    }

    public function testAListItemOrHeadingOrBlankLineDirectlyAfterADoneItemIsNoContinuation(): void
    {
        $done = '- [x] Item. Intakt: `ParityRecordTest::testAnIndentedDoneItemIsJudged`';
        $record = implode("\n", [$done, '- [ ] Next item.', $done, '## Next section', $done, '', 'A paragraph.', $done, '  * A nested item.', $done, '1. A numbered item.']);

        self::assertSame([], $this->repositoryTests()->unprovenItems($record));
    }

    public function testANonBlankLineAfterAnUncheckedItemIsNotJudged(): void
    {
        $record = "- [ ] Item. Intakt: `NoSuchParityTest::testAnything`\n  and more words";

        self::assertSame([], $this->repositoryTests()->unprovenItems($record));
    }

    public function testAnIndentedDoneItemIsJudged(): void
    {
        $record = '  - [x] A behavior. `test_old.py` [T1]';

        self::assertSame(
            ['line 1: names no Intakt test after `Intakt:`'],
            $this->repositoryTests()->unprovenItems($record),
        );
    }

    public function testATestFileUnderAFixturesFolderIsNoTestClass(): void
    {
        $tests = $this->scratchTests(['Fixtures/OnlyFixtureTest.php', 'A/TwinTest.php', 'Fixtures/TwinTest.php']);

        self::assertSame(
            ['line 1: OnlyFixtureTest is no test class under tests/', 'line 1: TwinTest is no test class under tests/'],
            $tests->unprovenItems('- [x] A behavior. [T1]; Intakt: `OnlyFixtureTest::testIt`, `TwinTest::testIt`'),
        );
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->scratches);
        $this->scratches = [];
    }

    protected function testAProtectedHelperIsNoTest(): void
    {
        $this->addToAssertionCount(1);
    }

    private function repositoryTests(): ParityRecord
    {
        return new ParityRecord(dirname(__DIR__, 2), 'Intakt\Tests');
    }

    /**
     * @return array<string, list<string>>
     */
    private static function notBuiltItemsOfTheRecord(): array
    {
        $record = (string) file_get_contents(dirname(__DIR__, 3).'/docs/architecture/parity.md');

        return new ParityRecord(dirname(__DIR__, 2), 'Intakt\Tests')->notBuiltItems($record);
    }

    /**
     * @param list<string> $files
     */
    private function scratchTests(array $files): ParityRecord
    {
        $scratch = sys_get_temp_dir().'/intakt-parity-record-'.bin2hex(random_bytes(6));
        $this->scratches[] = $scratch;
        foreach ($files as $file) {
            $path = $scratch.'/'.$file;
            if (!is_dir(dirname($path))) {
                mkdir(dirname($path), 0o700, true);
            }
            touch($path);
        }

        return new ParityRecord($scratch, 'Intakt\Tests\Scratch');
    }

    // A class alias lives for the whole process, so each call takes its own folder and alias, and a repeated run raises no warning.
    private function scratchTestsAliasing(string $class, string $testName): ParityRecord
    {
        $folder = 'Run'.bin2hex(random_bytes(6));
        class_alias($class, 'Intakt\Tests\Scratch\\'.$folder.'\\'.$testName);

        return $this->scratchTests([$folder.'/'.$testName.'.php']);
    }
}
