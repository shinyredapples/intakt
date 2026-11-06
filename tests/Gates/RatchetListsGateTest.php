<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\IgnoredErrorsReader;
use Intakt\Gates\PhpStanIncludes;
use Intakt\Gates\RatchetListFile;
use Intakt\Gates\RatchetListsGate;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(RatchetListsGate::class)]
#[CoversClass(PhpStanIncludes::class)]
#[CoversClass(RatchetListFile::class)]
#[CoversClass(IgnoredErrorsReader::class)]
final class RatchetListsGateTest extends SandboxTestCase
{
    private const string LIST = 'ratchet/loose-conditions.neon';
    private const string INCLUDE = '../../ratchet/loose-conditions.neon';
    private const string HEADER = "# Cleanup issue: #436\n";

    public function testAnAddedEntryFails(): void
    {
        $run = $this->changeList(
            $this->listOf(self::HEADER, $this->entry('#^Only booleans$#', 2, '../src/A.php')),
            $this->listOf(self::HEADER, $this->entry('#^Only booleans$#', 2, '../src/A.php').$this->entry('#^Only booleans$#', 1, '../src/B.php')),
        );

        self::assertTrue($run->failed('ratchet'), $run->output());
        self::assertStringContainsString('entry not on the target branch', $run->findingsOf('ratchet'));
        self::assertStringContainsString('src/B.php', $run->findingsOf('ratchet'));
    }

    public function testARaisedCountFails(): void
    {
        $run = $this->changeList(
            $this->listOf(self::HEADER, $this->entry('#^Only booleans$#', 2, '../src/A.php')),
            $this->listOf(self::HEADER, $this->entry('#^Only booleans$#', 3, '../src/A.php')),
        );

        self::assertTrue($run->failed('ratchet'), $run->output());
        self::assertStringContainsString('count rose from 2 to 3', $run->findingsOf('ratchet'));
    }

    public function testAWidenedMessagePatternFails(): void
    {
        $run = $this->changeList(
            $this->listOf(self::HEADER, $this->entry('#^Only booleans$#', 2, '../src/A.php')),
            $this->listOf(self::HEADER, $this->entry('#.*#', 2, '../src/A.php')),
        );

        self::assertTrue($run->failed('ratchet'), $run->output());
        self::assertStringContainsString('#.*#', $run->findingsOf('ratchet'));
    }

    public function testAChangedPathFails(): void
    {
        $run = $this->changeList(
            $this->listOf(self::HEADER, $this->entry('#^Only booleans$#', 2, '../src/A.php')),
            $this->listOf(self::HEADER, $this->entry('#^Only booleans$#', 2, '../src/Moved.php')),
        );

        self::assertTrue($run->failed('ratchet'), $run->output());
        self::assertStringContainsString('src/Moved.php', $run->findingsOf('ratchet'));
    }

    public function testAChangedIdentifierFails(): void
    {
        $run = $this->changeList(
            $this->listOf(self::HEADER, $this->entry('#^Only booleans$#', 2, '../src/A.php')),
            $this->listOf(self::HEADER, str_replace('if.condNotBoolean', 'if.other', $this->entry('#^Only booleans$#', 2, '../src/A.php'))),
        );

        self::assertTrue($run->failed('ratchet'), $run->output());
        self::assertStringContainsString('if.other', $run->findingsOf('ratchet'));
    }

    public function testADeletedEntryPasses(): void
    {
        $run = $this->changeList(
            $this->listOf(self::HEADER, $this->entry('#^Only booleans$#', 2, '../src/A.php').$this->entry('#^Only booleans$#', 1, '../src/B.php')),
            $this->listOf(self::HEADER, $this->entry('#^Only booleans$#', 2, '../src/A.php')),
        );

        self::assertTrue($run->passed('ratchet'), $run->output());
    }

    public function testALoweredCountPasses(): void
    {
        $run = $this->changeList(
            $this->listOf(self::HEADER, $this->entry('#^Only booleans$#', 2, '../src/A.php')),
            $this->listOf(self::HEADER, $this->entry('#^Only booleans$#', 1, '../src/A.php')),
        );

        self::assertTrue($run->passed('ratchet'), $run->output());
    }

    public function testAnUnchangedListPasses(): void
    {
        $list = $this->listOf(self::HEADER, $this->entry('#^Only booleans$#', 2, '../src/A.php'));

        self::assertTrue($this->changeList($list, $list)->passed('ratchet'));
    }

    public function testANewListPassesWithItsIncludeInTheSameChange(): void
    {
        $sandbox = $this->repositoryOnMain($this->configurationIncluding());
        $sandbox->write('config/gates/phpstan.neon', $this->configurationIncluding(self::INCLUDE));
        $sandbox->write(self::LIST, $this->listOf(self::HEADER, $this->entry('#^Only booleans$#', 2, '../src/A.php')));
        $sandbox->commitAll('feat: switch the rule on');

        $run = $sandbox->check('--only=ratchet', '--base=main');

        self::assertTrue($run->passed('ratchet'), $run->output());
    }

    public function testANewListFailsWhenItsIncludeIsAlreadyOnTheTargetBranch(): void
    {
        $sandbox = $this->repositoryOnMain($this->configurationIncluding(self::INCLUDE));
        $sandbox->write(self::LIST, $this->listOf(self::HEADER, $this->entry('#^Only booleans$#', 2, '../src/A.php')));
        $sandbox->commitAll('feat: bring a list back');

        $run = $sandbox->check('--only=ratchet', '--base=main');

        self::assertTrue($run->failed('ratchet'), $run->output());
        self::assertStringContainsString('arrives only with its include', $run->findingsOf('ratchet'));
    }

    public function testAListWithoutACleanupIssueInItsHeaderFails(): void
    {
        $list = $this->listOf("# Loose conditions\n", $this->entry('#^Only booleans$#', 2, '../src/A.php'));

        $run = $this->changeList($list, $list);

        self::assertTrue($run->failed('ratchet'), $run->output());
        self::assertStringContainsString('no cleanup issue in its header', $run->findingsOf('ratchet'));
    }

    public function testACleanupIssueBelowTheHeaderDoesNotCount(): void
    {
        $list = $this->listOf("# Loose conditions\n", $this->entry('#^Only booleans$#', 2, '../src/A.php'))."# Cleanup issue: #436\n";

        self::assertTrue($this->changeList($list, $list)->failed('ratchet'));
    }

    public function testAListThatSetsAnotherParameterFails(): void
    {
        $list = $this->listOf(self::HEADER, $this->entry('#^Only booleans$#', 2, '../src/A.php'))."\treportUnmatchedIgnoredErrors: false\n";

        $run = $this->changeList($list, $list);

        self::assertTrue($run->failed('ratchet'), $run->output());
        self::assertStringContainsString('holds more than parameters.ignoreErrors', $run->findingsOf('ratchet'));
    }

    public function testAListThatRegistersAServiceFails(): void
    {
        $list = $this->listOf(self::HEADER, $this->entry('#^Only booleans$#', 2, '../src/A.php'))."services:\n\t- Intakt\\Sample\\Filter\n";

        $run = $this->changeList($list, $list);

        self::assertTrue($run->failed('ratchet'), $run->output());
        self::assertStringContainsString('holds more than parameters.ignoreErrors', $run->findingsOf('ratchet'));
    }

    public function testAnEntryWithoutACountFails(): void
    {
        $entry = "\t\t-\n\t\t\tmessage: '#^Only booleans$#'\n\t\t\tidentifier: if.condNotBoolean\n\t\t\tpath: ../src/A.php\n";
        $list = $this->listOf(self::HEADER, $entry);

        $run = $this->changeList($list, $list);

        self::assertTrue($run->failed('ratchet'), $run->output());
        self::assertStringContainsString('entry 1 needs', $run->findingsOf('ratchet'));
    }

    public function testAnEntryWithAPathOutsideTheRepositoryFails(): void
    {
        $list = $this->listOf(self::HEADER, $this->entry('#^Only booleans$#', 2, '../../../elsewhere/A.php'));

        self::assertTrue($this->changeList($list, $list)->failed('ratchet'));
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
        $sandbox = $this->repositoryOnMain($this->configurationIncluding(self::INCLUDE), $this->listOf(self::HEADER, $this->entry('#^Only booleans$#', 2, '../src/A.php')));
        $sandbox->write('config/gates/phpstan.neon', $this->configurationIncluding());
        $sandbox->git('rm', '--quiet', self::LIST);
        $sandbox->commitAll('fix: finish the cleanup');

        self::assertTrue($sandbox->check('--only=ratchet', '--base=main')->passed('ratchet'));
    }

    public function testTheIncludesOfThisRepositoryHoldNoRatchetListToday(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('config/gates/phpstan.neon', (string) file_get_contents(dirname(__DIR__, 2).'/config/gates/phpstan.neon'));

        $run = $sandbox->check('--only=ratchet');

        self::assertTrue($run->passed('ratchet'), $run->output());
        self::assertSame('no ratchet lists', $run->noteOf('ratchet'));
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
        $run = $this->growListIncludedBy("includes: [../../ratchet/loose-conditions.neon]\nparameters:\n    level: max\n");

        self::assertTrue($run->failed('ratchet'), $run->output());
        self::assertStringContainsString('cannot read the includes', $run->findingsOf('ratchet'));
    }

    public function testAPlaceholderIncludeDoesNotHideAGrowingList(): void
    {
        $run = $this->growListIncludedBy("includes:\n    - %currentWorkingDirectory%/ratchet/loose-conditions.neon\nparameters:\n    level: max\n");

        self::assertTrue($run->failed('ratchet'), $run->output());
        self::assertStringContainsString('cannot read the includes', $run->findingsOf('ratchet'));
    }

    public function testAnIncludesKeyFollowedByACommentDoesNotHideAGrowingList(): void
    {
        $run = $this->growListIncludedBy("includes: # lists\n    - ".self::INCLUDE."\nparameters:\n    level: max\n");

        self::assertTrue($run->failed('ratchet'), $run->output());
        self::assertStringContainsString('cannot read the includes', $run->findingsOf('ratchet'));
    }

    public function testDeletingTheLastEntryWithAnEmptyListPasses(): void
    {
        $run = $this->changeList($this->listOf(self::HEADER, $this->entry('#^Only booleans$#', 2, '../src/A.php')), self::HEADER."parameters:\n\tignoreErrors: []\n");

        self::assertTrue($run->passed('ratchet'), $run->output());
    }

    public function testAnEmptyListThatSetsAnotherParameterFails(): void
    {
        $run = $this->changeList(self::HEADER."parameters:\n\tignoreErrors: []\n", self::HEADER."parameters:\n\tignoreErrors: []\n\treportUnmatchedIgnoredErrors: false\n");

        self::assertTrue($run->failed('ratchet'), $run->output());
        self::assertStringContainsString('holds more than parameters.ignoreErrors', $run->findingsOf('ratchet'));
    }

    public function testAListWithoutABaseBranchFails(): void
    {
        $sandbox = $this->repositoryOnMain($this->configurationIncluding(self::INCLUDE));

        self::assertTrue($sandbox->check('--only=ratchet', '--base=missing')->failed('ratchet'));
    }

    private function changeList(string $before, string $after): CheckRun
    {
        $sandbox = $this->repositoryOnMain($this->configurationIncluding(self::INCLUDE), $before);
        $sandbox->write(self::LIST, $after);
        $sandbox->commitAll('fix: change the list');

        return $sandbox->check('--only=ratchet', '--base=main');
    }

    private function growListIncludedBy(string $configuration): CheckRun
    {
        $sandbox = $this->repositoryOnMain($configuration, $this->listOf(self::HEADER, $this->entry('#^Only booleans$#', 1, '../src/A.php')));
        $sandbox->write(self::LIST, $this->listOf(self::HEADER, $this->entry('#^Only booleans$#', 5, '../src/A.php')));
        $sandbox->commitAll('fix: grow the list');

        return $sandbox->check('--only=ratchet', '--base=main');
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

    private function configurationIncluding(string ...$lists): string
    {
        $includes = array_map(static fn (string $list): string => '    - '.$list."\n", ['../../vendor/phpstan/phpstan-strict-rules/rules.neon', 'phpstan-cache.php', ...$lists]);

        return "includes:\n".implode('', $includes)."\nparameters:\n    level: max\n";
    }

    private function listOf(string $header, string $entries): string
    {
        return $header."parameters:\n\tignoreErrors:\n".$entries;
    }

    private function entry(string $message, int $count, string $path): string
    {
        return "\t\t-\n\t\t\tmessage: '".$message."'\n\t\t\tidentifier: if.condNotBoolean\n\t\t\tcount: ".$count."\n\t\t\tpath: ".$path."\n";
    }
}
