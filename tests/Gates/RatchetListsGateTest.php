<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\IgnoredErrors;
use Intakt\Gates\IgnoredErrorsReader;
use Intakt\Gates\Mode;
use Intakt\Gates\PhpStanIncludes;
use Intakt\Gates\RatchetListFile;
use Intakt\Gates\RatchetListsGate;
use Intakt\Gates\ScratchDirectory;
use Intakt\Gates\Workspace;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(RatchetListsGate::class)]
#[CoversClass(PhpStanIncludes::class)]
#[CoversClass(RatchetListFile::class)]
#[CoversClass(IgnoredErrorsReader::class)]
#[UsesClass(IgnoredErrors::class)]
#[UsesClass(ScratchDirectory::class)]
#[UsesClass(Workspace::class)]
final class RatchetListsGateTest extends SandboxTestCase
{
    private const string LIST = 'ratchet/loose-conditions.neon';
    private const string INCLUDE = '../../ratchet/loose-conditions.neon';
    private const string HEADER = "# Cleanup issue: #436\n";

    // Because every bin/check run costs two PHPStan dumps, the list changes share one repository and one run.
    public function testTheListChangesFailTheGateInOneRun(): CheckRun
    {
        $run = $this->changeLists(self::listChanges());

        self::assertTrue($run->failed('ratchet'), $run->output());

        $lists = array_column(self::listChanges(), 0);

        foreach (array_filter(explode("\n", $run->findingsOf('ratchet')), static fn (string $line): bool => '' !== $line) as $finding) {
            self::assertNotEmpty(array_filter($lists, static fn (string $list): bool => str_starts_with($finding, $list.':')), $finding);
        }

        return $run;
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('listOutcomes')]
    #[Depends('testTheListChangesFailTheGateInOneRun')]
    public function testEachListChangeIsJudgedAgainstItsTargetBranchVersion(string $list, array $expected, CheckRun $run): void
    {
        $findings = $this->findingsFor($run, $list);

        self::assertSame([] === $expected, '' === $findings, $findings);

        foreach ($expected as $text) {
            self::assertStringContainsString($text, $findings);
        }
    }

    /**
     * @return array<string, array{string, list<string>}>
     */
    public static function listOutcomes(): array
    {
        return array_map(static fn (array $change): array => [$change[0], $change[3]], self::listChanges());
    }

    public function testANewListPassesWithItsIncludeInTheSameChange(): void
    {
        $sandbox = $this->repositoryOnMain($this->configurationIncluding());
        $sandbox->write('config/gates/phpstan.neon', $this->configurationIncluding(self::INCLUDE));
        $sandbox->write(self::LIST, self::listOf(self::HEADER, self::entry('#^Only booleans$#', 2, '../src/A.php')));
        $sandbox->commitAll('feat: switch the rule on');

        $run = $sandbox->check('--only=ratchet', '--base=main');

        self::assertTrue($run->passed('ratchet'), $run->output());
    }

    public function testANewListFailsWhenItsIncludeIsAlreadyOnTheTargetBranch(): void
    {
        $sandbox = $this->repositoryOnMain($this->configurationIncluding(self::INCLUDE));
        $sandbox->write(self::LIST, self::listOf(self::HEADER, self::entry('#^Only booleans$#', 2, '../src/A.php')));
        $sandbox->commitAll('feat: bring a list back');

        $run = $sandbox->check('--only=ratchet', '--base=main');

        self::assertTrue($run->failed('ratchet'), $run->output());
        self::assertStringContainsString('arrives only with its include', $run->findingsOf('ratchet'));
    }

    public function testAListWithoutACleanupIssueInItsHeaderFails(): void
    {
        $list = self::listOf("# Loose conditions\n", self::entry('#^Only booleans$#', 2, '../src/A.php'));
        $sandbox = $this->repositoryOnMain($this->configurationIncluding(self::INCLUDE), $list);

        $run = $sandbox->check('--only=ratchet', '--base=main');

        self::assertTrue($run->failed('ratchet'), $run->output());
        self::assertStringContainsString(self::LIST.': no cleanup issue in its header', $run->findingsOf('ratchet'));
    }

    public function testACleanupIssueBelowTheHeaderDoesNotCount(): void
    {
        $list = self::listOf("# Loose conditions\n", self::entry('#^Only booleans$#', 2, '../src/A.php'))."# Cleanup issue: #436\n";

        self::assertContains(self::LIST.': no cleanup issue in its header, add a line "# Cleanup issue: #<number>" above the entries', new RatchetListFile(self::LIST, $list)->problems());
    }

    public function testAListOfIgnoredErrorsUnderItsCleanupIssueHasNoProblems(): void
    {
        $list = self::listOf(self::HEADER, self::entry('#^Only booleans$#', 2, '../src/A.php'));

        self::assertSame([], new RatchetListFile(self::LIST, $list)->problems());
    }

    public function testAListThatSetsAnotherParameterFails(): void
    {
        $list = self::listOf(self::HEADER, self::entry('#^Only booleans$#', 2, '../src/A.php'))."\treportUnmatchedIgnoredErrors: false\n";

        self::assertSame([self::LIST.': holds more than parameters.ignoreErrors, so it could change the analysis'], new RatchetListFile(self::LIST, $list)->problems());
    }

    public function testAListThatRegistersAServiceFails(): void
    {
        $list = self::listOf(self::HEADER, self::entry('#^Only booleans$#', 2, '../src/A.php'))."services:\n\t- Intakt\\Sample\\Filter\n";

        self::assertSame([self::LIST.': holds more than parameters.ignoreErrors, so it could change the analysis'], new RatchetListFile(self::LIST, $list)->problems());
    }

    public function testAnEmptyListThatSetsAnotherParameterFails(): void
    {
        $list = self::HEADER."parameters:\n\tignoreErrors: []\n\treportUnmatchedIgnoredErrors: false\n";

        self::assertSame([self::LIST.': holds more than parameters.ignoreErrors, so it could change the analysis'], new RatchetListFile(self::LIST, $list)->problems());
    }

    // Because every list costs a PHPStan dump, the malformed entries are read side by side in one call.
    /**
     * @return array<string, IgnoredErrors>
     */
    public function testTheMalformedEntriesAreReadSideBySide(): array
    {
        $withoutCount = "\t\t-\n\t\t\tmessage: '#^Only booleans$#'\n\t\t\tidentifier: if.condNotBoolean\n\t\t\tpath: ../src/A.php\n";

        $read = $this->readEntriesOf([
            'without a count' => self::listOf(self::HEADER, $withoutCount),
            'outside the repository' => self::listOf(self::HEADER, self::entry('#^Only booleans$#', 2, '../../../elsewhere/A.php')),
        ]);

        self::assertSame(['without a count', 'outside the repository'], array_keys($read));

        return $read;
    }

    /**
     * @param array<string, IgnoredErrors> $entries
     */
    #[Depends('testTheMalformedEntriesAreReadSideBySide')]
    public function testAnEntryWithoutACountFails(array $entries): void
    {
        $read = $entries['without a count'];

        self::assertSame([], $read->counts);
        self::assertSame(['entry 1 needs exactly identifier, path inside the repository, count and one of message or rawMessage'], $read->problems);
    }

    /**
     * @param array<string, IgnoredErrors> $entries
     */
    #[Depends('testTheMalformedEntriesAreReadSideBySide')]
    public function testAnEntryWithAPathOutsideTheRepositoryFails(array $entries): void
    {
        $read = $entries['outside the repository'];

        self::assertSame([], $read->counts);
        self::assertSame(['entry 1 needs exactly identifier, path inside the repository, count and one of message or rawMessage'], $read->problems);
    }

    public function testAnIncludedListThatIsMissingFails(): void
    {
        $sandbox = $this->repositoryOnMain($this->configurationIncluding());
        $sandbox->write('config/gates/phpstan.neon', $this->configurationIncluding(self::INCLUDE));
        $sandbox->commitAll('feat: include a list');

        $run = $sandbox->check('--only=ratchet', '--base=main');

        self::assertTrue($run->failed('ratchet'), $run->output());
        self::assertStringContainsString('included but missing', $run->findingsOf('ratchet'));
    }

    public function testRemovingAListWithItsIncludePasses(): void
    {
        $sandbox = $this->repositoryOnMain($this->configurationIncluding(self::INCLUDE), self::listOf(self::HEADER, self::entry('#^Only booleans$#', 2, '../src/A.php')));
        $sandbox->write('config/gates/phpstan.neon', $this->configurationIncluding());
        $sandbox->git('rm', '--quiet', self::LIST);
        $sandbox->commitAll('fix: finish the cleanup');

        self::assertTrue($sandbox->check('--only=ratchet', '--base=main')->passed('ratchet'));
    }

    // the note of a tree without git comes only after the includes were read and named a list, so it shows that the provider literal list is found
    public function testTheIncludesOfThisRepositoryNameARatchetListToday(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('config/gates/phpstan.neon', (string) file_get_contents(dirname(__DIR__, 2).'/config/gates/phpstan.neon'));

        $run = $sandbox->check('--only=ratchet');

        self::assertTrue($run->passed('ratchet'), $run->output());
        self::assertSame('skipped, no git history in this tree', $run->noteOf('ratchet'));
    }

    public function testAnIncludeOutsideTheRepositoryFails(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('config/gates/phpstan.neon', $this->configurationIncluding('../../../elsewhere.neon'));

        $run = $sandbox->check('--only=ratchet');

        self::assertTrue($run->failed('ratchet'), $run->output());
        self::assertStringContainsString('cannot read the includes', $run->findingsOf('ratchet'));
    }

    public function testAFlowFormIncludeDoesNotHideAGrowingList(): void
    {
        self::assertNull(new PhpStanIncludes()->ratchetListsIn("includes: [../../ratchet/loose-conditions.neon]\nparameters:\n    level: max\n"));
    }

    public function testAPlaceholderIncludeDoesNotHideAGrowingList(): void
    {
        self::assertNull(new PhpStanIncludes()->ratchetListsIn("includes:\n    - %currentWorkingDirectory%/ratchet/loose-conditions.neon\nparameters:\n    level: max\n"));
    }

    public function testAnIncludesKeyFollowedByACommentDoesNotHideAGrowingList(): void
    {
        self::assertNull(new PhpStanIncludes()->ratchetListsIn("includes: # lists\n    - ".self::INCLUDE."\nparameters:\n    level: max\n"));
    }

    public function testAnUnindentedIncludeItemDoesNotHideAGrowingList(): void
    {
        self::assertSame([self::LIST], new PhpStanIncludes()->ratchetListsIn("includes:\n- ".self::INCLUDE."\nparameters:\n    level: max\n"));
    }

    public function testAnIndentedIncludeItemIsAList(): void
    {
        self::assertSame([self::LIST], new PhpStanIncludes()->ratchetListsIn($this->configurationIncluding(self::INCLUDE)));
    }

    public function testAnIndentedConfigurationDoesNotHideAGrowingList(): void
    {
        self::assertNull(new PhpStanIncludes()->ratchetListsIn("    includes:\n        - ".self::INCLUDE."\n    parameters:\n        level: max\n"));
    }

    public function testAListWithoutABaseBranchFails(): void
    {
        $sandbox = $this->repositoryOnMain($this->configurationIncluding(self::INCLUDE));

        self::assertTrue($sandbox->check('--only=ratchet', '--base=missing')->failed('ratchet'));
    }

    /**
     * @return array<string, array{string, string, string, list<string>}>
     */
    private static function listChanges(): array
    {
        $entry = self::entry('#^Only booleans$#', 2, '../src/A.php');

        return [
            'an added entry fails' => ['ratchet/added-entry.neon', self::listOf(self::HEADER, $entry), self::listOf(self::HEADER, $entry.self::entry('#^Only booleans$#', 1, '../src/B.php')), ['entry not on the target branch', 'src/B.php']],
            'a raised count fails' => ['ratchet/raised-count.neon', self::listOf(self::HEADER, $entry), self::listOf(self::HEADER, self::entry('#^Only booleans$#', 3, '../src/A.php')), ['count rose from 2 to 3']],
            'a widened message pattern fails' => ['ratchet/widened-pattern.neon', self::listOf(self::HEADER, $entry), self::listOf(self::HEADER, self::entry('#.*#', 2, '../src/A.php')), ['entry not on the target branch', '#.*#']],
            'a changed path fails' => ['ratchet/changed-path.neon', self::listOf(self::HEADER, $entry), self::listOf(self::HEADER, self::entry('#^Only booleans$#', 2, '../src/Moved.php')), ['entry not on the target branch', 'src/Moved.php']],
            'a changed identifier fails' => ['ratchet/changed-identifier.neon', self::listOf(self::HEADER, $entry), self::listOf(self::HEADER, str_replace('if.condNotBoolean', 'if.other', $entry)), ['entry not on the target branch', 'if.other']],
            'a deleted entry passes' => ['ratchet/deleted-entry.neon', self::listOf(self::HEADER, $entry.self::entry('#^Only booleans$#', 1, '../src/B.php')), self::listOf(self::HEADER, $entry), []],
            'a lowered count passes' => ['ratchet/lowered-count.neon', self::listOf(self::HEADER, $entry), self::listOf(self::HEADER, self::entry('#^Only booleans$#', 1, '../src/A.php')), []],
            'an unchanged list passes' => ['ratchet/unchanged.neon', self::listOf(self::HEADER, $entry), self::listOf(self::HEADER, $entry), []],
            'deleting the last entry with an empty list passes' => ['ratchet/emptied.neon', self::listOf(self::HEADER, $entry), self::HEADER."parameters:\n\tignoreErrors: []\n", []],
        ];
    }

    /**
     * @param array<string, array{string, string, string, list<string>}> $changes
     */
    private function changeLists(array $changes): CheckRun
    {
        // Because NEON lets an item sit at its key's indentation, the unindented includes also prove that such a list is read.
        $includes = array_map(static fn (array $change): string => '- ../../'.$change[0]."\n", $changes);
        $sandbox = $this->emptySandbox();
        $sandbox->startRepository();
        $sandbox->write('config/gates/phpstan.neon', "includes:\n".implode('', $includes)."parameters:\n    level: max\n");

        foreach ($changes as [$list, $before]) {
            $sandbox->write($list, $before);
        }

        $sandbox->commitAll('chore: start');
        $sandbox->git('switch', '--quiet', '--create', 'change');

        foreach ($changes as [$list, , $after]) {
            $sandbox->write($list, $after);
        }

        $sandbox->commitAll('fix: change the lists');

        return $sandbox->check('--only=ratchet', '--base=main');
    }

    private function findingsFor(CheckRun $run, string $list): string
    {
        $findings = array_filter(explode("\n", $run->findingsOf('ratchet')), static fn (string $finding): bool => str_starts_with($finding, $list.':'));

        return implode("\n", $findings);
    }

    /**
     * @param array<string, string> $lists
     *
     * @return array<string, IgnoredErrors>
     */
    private function readEntriesOf(array $lists): array
    {
        $workspace = new Workspace($this->emptySandbox()->root, dirname(__DIR__, 2), Mode::Full, null);
        $scratch = ScratchDirectory::create('intakt-ratchet-test');
        self::assertInstanceOf(ScratchDirectory::class, $scratch);

        try {
            return new IgnoredErrorsReader()->read($workspace, $scratch, array_map(static fn (string $list): RatchetListFile => new RatchetListFile(self::LIST, $list), $lists));
        } finally {
            $scratch->remove();
        }
    }

    private function configurationIncluding(string ...$lists): string
    {
        $includes = array_map(static fn (string $list): string => '    - '.$list."\n", ['../../vendor/phpstan/phpstan-strict-rules/rules.neon', 'phpstan-cache.php', ...$lists]);

        return "includes:\n".implode('', $includes)."\nparameters:\n    level: max\n";
    }

    private function repositoryOnMain(string $configuration, ?string $list = null): Sandbox
    {
        $sandbox = $this->emptySandbox();
        $sandbox->startRepository();
        $sandbox->write('config/gates/phpstan.neon', $configuration);

        if (null !== $list) {
            $sandbox->write(self::LIST, $list);
        }

        $sandbox->commitAll('chore: start');
        $sandbox->git('switch', '--quiet', '--create', 'change');

        return $sandbox;
    }

    private static function entry(string $message, int $count, string $path): string
    {
        return "\t\t-\n\t\t\tmessage: '".$message."'\n\t\t\tidentifier: if.condNotBoolean\n\t\t\tcount: ".$count."\n\t\t\tpath: ".$path."\n";
    }

    private static function listOf(string $header, string $entries): string
    {
        return $header."parameters:\n\tignoreErrors:\n".$entries;
    }
}
