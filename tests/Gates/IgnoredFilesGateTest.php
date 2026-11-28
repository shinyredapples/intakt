<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\GateResult;
use Intakt\Gates\IgnoredFilesGate;
use Intakt\Gates\Workspace;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(IgnoredFilesGate::class)]
#[UsesClass(Workspace::class)]
#[UsesClass(GateResult::class)]
final class IgnoredFilesGateTest extends SandboxTestCase
{
    public function testAForceAddedIgnoredFileFailsTheGateWithItsPath(): void
    {
        $sandbox = $this->repositoryIgnoring("*.log\n");
        $sandbox->write('var/debug.log', "trace\n");
        $sandbox->git('add', '--force', 'var/debug.log');

        $run = $sandbox->check('--only=ignored');

        self::assertSame(1, $run->exitCode(), $run->output());
        self::assertTrue($run->failed('ignored'), $run->output());
        self::assertStringContainsString('var/debug.log: tracked, but .gitignore excludes it', $run->findingsOf('ignored'));
    }

    public function testARepositoryWithNothingIgnoredTrackedPasses(): void
    {
        $sandbox = $this->repositoryIgnoring("*.log\n");
        $sandbox->write('var/debug.log', "trace\n");
        $sandbox->write('src/Report.php', "<?php\n");
        $sandbox->commitAll('feat: add the report');

        $run = $sandbox->runGate(new IgnoredFilesGate());

        self::assertTrue($run->passed('ignored'), $run->output());
    }

    public function testAnIgnoredFileInANestedGitignoreFailsTheGate(): void
    {
        $sandbox = $this->repositoryIgnoring("*.log\n");
        $sandbox->write('docs/.gitignore', "draft.md\n");
        $sandbox->write('docs/draft.md', "# Draft\n");
        $sandbox->git('add', '--force', 'docs');

        $run = $sandbox->runGate(new IgnoredFilesGate());

        self::assertTrue($run->failed('ignored'), $run->output());
        self::assertStringContainsString('docs/draft.md', $run->findingsOf('ignored'));
    }

    public function testAFileExcludedOnlyByInfoExcludePasses(): void
    {
        $sandbox = $this->repositoryIgnoring("*.log\n");
        $sandbox->write('.git/info/exclude', "notes.txt\n");
        $sandbox->write('notes.txt', "mine\n");
        $sandbox->git('add', '--force', 'notes.txt');

        $run = $sandbox->runGate(new IgnoredFilesGate());

        self::assertTrue($run->passed('ignored'), $run->output());
    }

    public function testAFileExcludedOnlyByARepositoryLocalExcludesFilePasses(): void
    {
        $sandbox = $this->repositoryIgnoring("*.log\n");
        $sandbox->write('.git/local-excludes', "notes.txt\n");
        $sandbox->git('config', 'core.excludesFile', '.git/local-excludes');
        $sandbox->write('notes.txt', "mine\n");
        $sandbox->git('add', '--force', 'notes.txt');

        $run = $sandbox->runGate(new IgnoredFilesGate());

        self::assertTrue($run->passed('ignored'), $run->output());
    }

    public function testAnIgnoredFileTrackedInAnEarlierCommitFailsWhenTheLatestCommitChangesAnotherFile(): void
    {
        $sandbox = $this->repositoryIgnoring("*.log\n");
        $sandbox->write('var/debug.log', "trace\n");
        $sandbox->git('add', '--force', 'var/debug.log');
        $sandbox->git('commit', '--quiet', '--message', 'chore: keep the log');
        $sandbox->git('switch', '--quiet', '--create', 'feature');
        $sandbox->write('README.md', "# Readme\n");
        $sandbox->commitAll('docs: add the readme');

        $run = $sandbox->runGate(new IgnoredFilesGate(), 'main');

        self::assertTrue($run->failed('ignored'), $run->output());
        self::assertStringContainsString('var/debug.log', $run->findingsOf('ignored'));
    }

    public function testANegatedRuleInGitignoreLetsATrackedFilePass(): void
    {
        $sandbox = $this->repositoryIgnoring("*.log\n!/var/kept.log\n");
        $sandbox->write('var/kept.log', "kept\n");
        $sandbox->commitAll('chore: keep one log');

        $run = $sandbox->runGate(new IgnoredFilesGate());

        self::assertTrue($run->passed('ignored'), $run->output());
    }

    public function testANegatedRuleUnderAnExcludedDirectoryStillFailsTheGate(): void
    {
        $sandbox = $this->repositoryIgnoring("/out/\n!/out/a.txt\n");
        $sandbox->write('out/a.txt', "kept\n");
        $sandbox->git('add', '--force', 'out/a.txt');

        $run = $sandbox->runGate(new IgnoredFilesGate());

        self::assertTrue($run->failed('ignored'), $run->output());
        self::assertStringContainsString('out/a.txt', $run->findingsOf('ignored'));
    }

    public function testANegatedRuleUnderADirectoryContentsRuleLetsATrackedFilePass(): void
    {
        $sandbox = $this->repositoryIgnoring("/out/*\n!/out/a.txt\n");
        $sandbox->write('out/a.txt', "kept\n");
        $sandbox->commitAll('chore: keep one output');

        $run = $sandbox->runGate(new IgnoredFilesGate());

        self::assertTrue($run->passed('ignored'), $run->output());
    }

    public function testANegatedRuleWithUnescapedBracketsReIncludesTheGlobMatchAndLeavesTheLiteralNameFailing(): void
    {
        $sandbox = $this->repositoryIgnoring("*.tmp\n!build[1].tmp\n");
        $sandbox->write('build[1].tmp', "data\n");
        $sandbox->write('build1.tmp', "data\n");
        $sandbox->git('add', '--force', '--', 'build[1].tmp', 'build1.tmp');

        $result = $sandbox->gateResult(new IgnoredFilesGate());

        self::assertSame(['build[1].tmp: tracked, but .gitignore excludes it'], $result->findings);
    }

    public function testANegatedRuleWithEscapedBracketsReIncludesOnlyTheLiteralName(): void
    {
        $sandbox = $this->repositoryIgnoring("*.tmp\n!/build\\[1\\].tmp\n");
        $sandbox->write('build[1].tmp', "data\n");
        $sandbox->write('build1.tmp', "data\n");
        $sandbox->git('add', '--force', '--', 'build[1].tmp', 'build1.tmp');

        $result = $sandbox->gateResult(new IgnoredFilesGate());

        self::assertSame(['build1.tmp: tracked, but .gitignore excludes it'], $result->findings);
    }

    public function testTheDocumentedUntrackCommandWithAShellQuotedGlobNameUntracksOnlyThatFile(): void
    {
        $sandbox = $this->repositoryIgnoring("*.tmp\n!build1.tmp\n");
        $sandbox->write('build[1].tmp', "data\n");
        $sandbox->write('build1.tmp', "data\n");
        $sandbox->git('add', '--force', '--', 'build[1].tmp', 'build1.tmp');

        $sandbox->runScript("git --literal-pathspecs rm --cached -- 'build[1].tmp'");

        self::assertSame("build1.tmp\n", $sandbox->git('ls-files', '--', '*.tmp')->output);
    }

    public function testTheDocumentedUntrackCommandWithAnApostropheWrittenAsQuoteBackslashQuoteQuoteUntracksOnlyThatFile(): void
    {
        $sandbox = $this->repositoryIgnoring("*.tmp\n!ab.tmp\n");
        $sandbox->write("a''b.tmp", "data\n");
        $sandbox->write('ab.tmp', "data\n");
        $sandbox->git('add', '--force', '--', "a''b.tmp", 'ab.tmp');

        $sandbox->runScript("git --literal-pathspecs rm --cached -- '".str_replace("'", "'\\''", "a''b.tmp")."'");

        self::assertSame("ab.tmp\n", $sandbox->git('ls-files', '--', '*.tmp')->output);
    }

    public function testAQuotedPathTypedBetweenDollarQuotesUntracksTheFileItNames(): void
    {
        $sandbox = $this->repositoryIgnoring("*.tmp\n");
        $sandbox->write("report-\xFF.tmp", "data\n");
        $sandbox->git('add', '--force', "report-\xFF.tmp");
        $quoted = strstr($this->fullFindingsOf($sandbox->gateResult(new IgnoredFilesGate()))[0], ': tracked', true);

        $sandbox->runScript("git --literal-pathspecs rm --cached -- $'".substr((string) $quoted, 1, -1)."'");

        self::assertTrue($sandbox->runGate(new IgnoredFilesGate())->passed('ignored'));
    }

    public function testAPathWithThreeIndexStagesGivesExactlyOneFinding(): void
    {
        $sandbox = $this->repositoryIgnoring("*.log\n");
        $sandbox->write('blob.txt', "conflict\n");
        $blob = trim($sandbox->git('hash-object', '-w', 'blob.txt')->output);
        $entries = implode('', array_map(static fn (int $stage): string => '100644 '.$blob.' '.$stage."\tbuild.log\n", [1, 2, 3]));
        $sandbox->runScript('printf %s "$ENTRIES" | git update-index --index-info', ['ENTRIES' => $entries]);

        $result = $sandbox->gateResult(new IgnoredFilesGate());

        self::assertSame(['build.log: tracked, but .gitignore excludes it'], $result->findings);
    }

    public function testAPathWithByteFfIsQuotedWithOctalInTheFullReport(): void
    {
        $sandbox = $this->repositoryIgnoring("*.tmp\n");
        $sandbox->write("report-\xFF.tmp", "data\n");
        $sandbox->git('add', '--force', "report-\xFF.tmp");

        $findings = $this->fullFindingsOf($sandbox->gateResult(new IgnoredFilesGate()));

        self::assertSame(['"report-\377.tmp": tracked, but .gitignore excludes it'], $findings);
    }

    public function testAPathWithANewlineIsQuotedWithCEscapesInTheFullReport(): void
    {
        $sandbox = $this->repositoryIgnoring("*.tmp\n");
        $sandbox->write("notes\n\"draft\".tmp", "data\n");
        $sandbox->git('add', '--force', "notes\n\"draft\".tmp");

        $findings = $this->fullFindingsOf($sandbox->gateResult(new IgnoredFilesGate()));

        self::assertSame(['"notes\n\"draft\".tmp": tracked, but .gitignore excludes it'], $findings);
    }

    public function testAPathEndingInANewlineIsQuotedWithCEscapesInTheFullReport(): void
    {
        $sandbox = $this->repositoryIgnoring("report*\n");
        $sandbox->write("report.tmp\n", "data\n");
        $sandbox->git('add', '--force', "report.tmp\n");

        $findings = $this->fullFindingsOf($sandbox->gateResult(new IgnoredFilesGate()));

        self::assertSame(['"report.tmp\n": tracked, but .gitignore excludes it'], $findings);
    }

    public function testAPathWithARightToLeftOverrideIsQuotedWithOctalBytesInTheFullReport(): void
    {
        $sandbox = $this->repositoryIgnoring("*.tmp\n");
        $sandbox->write("one\u{202E}owt.tmp", "data\n");
        $sandbox->git('add', '--force', "one\u{202E}owt.tmp");

        $findings = $this->fullFindingsOf($sandbox->gateResult(new IgnoredFilesGate()));

        self::assertSame(['"one\342\200\256owt.tmp": tracked, but .gitignore excludes it'], $findings);
    }

    public function testAPathWithAUnicodeNextLineIsQuotedWithOctalBytesInTheFullReport(): void
    {
        $sandbox = $this->repositoryIgnoring("*.tmp\n");
        $sandbox->write("one\u{0085}two.tmp", "data\n");
        $sandbox->git('add', '--force', "one\u{0085}two.tmp");

        $findings = $this->fullFindingsOf($sandbox->gateResult(new IgnoredFilesGate()));

        self::assertSame(['"one\302\205two.tmp": tracked, but .gitignore excludes it'], $findings);
    }

    public function testAPathWithAUnicodeLineSeparatorIsQuotedWithOctalBytesInTheFullReport(): void
    {
        $sandbox = $this->repositoryIgnoring("*.tmp\n");
        $sandbox->write("one\u{2028}two.tmp", "data\n");
        $sandbox->git('add', '--force', "one\u{2028}two.tmp");

        $findings = $this->fullFindingsOf($sandbox->gateResult(new IgnoredFilesGate()));

        self::assertSame(['"one\342\200\250two.tmp": tracked, but .gitignore excludes it'], $findings);
    }

    public function testAPathWithATabAndABellIsQuotedWithCEscapesInTheFullReport(): void
    {
        $sandbox = $this->repositoryIgnoring("*.tmp\n");
        $sandbox->write("a\tb\x07\\c.tmp", "data\n");
        $sandbox->git('add', '--force', "a\tb\x07\\c.tmp");

        $findings = $this->fullFindingsOf($sandbox->gateResult(new IgnoredFilesGate()));

        self::assertSame(['"a\tb\a\\\\c.tmp": tracked, but .gitignore excludes it'], $findings);
    }

    public function testAPathInValidUtf8WithoutControlCharactersStaysAsItIs(): void
    {
        $sandbox = $this->repositoryIgnoring("*.tmp\n");
        $sandbox->write('café "menu".tmp', "data\n");
        $sandbox->git('add', '--force', 'café "menu".tmp');

        $findings = $this->fullFindingsOf($sandbox->gateResult(new IgnoredFilesGate()));

        self::assertSame(['café "menu".tmp: tracked, but .gitignore excludes it'], $findings);
    }

    public function testAPathThatStartsWithADoubleQuoteIsQuotedSoItCannotPassForAQuotedOne(): void
    {
        $sandbox = $this->repositoryIgnoring("*.tmp\n");
        $sandbox->write('"a\n".tmp', "data\n");
        $sandbox->git('add', '--force', '"a\n".tmp');

        $findings = $this->fullFindingsOf($sandbox->gateResult(new IgnoredFilesGate()));

        self::assertSame(['"\"a\\\\n\".tmp": tracked, but .gitignore excludes it'], $findings);
    }

    public function testAnUnreadableIndexFailsTheGateWithGitsError(): void
    {
        $sandbox = $this->repositoryIgnoring("*.log\n");
        $sandbox->write('.git/index', 'not an index');

        $run = $sandbox->runGate(new IgnoredFilesGate());

        self::assertTrue($run->failed('ignored'), $run->output());
        self::assertStringContainsString('git ls-files failed', $run->findingsOf('ignored'));
    }

    public function testATreeWithoutGitPassesWithANote(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('notes.txt', "plain\n");

        $run = $sandbox->runGate(new IgnoredFilesGate());

        self::assertTrue($run->passed('ignored'), $run->output());
        self::assertStringContainsString('no git index', $run->noteOf('ignored'));
    }

    public function testEmptyingTheCommittedGitignoreWithoutStagingFailsTheGateForThatRuleFile(): void
    {
        $sandbox = $this->repositoryIgnoring("*.log\n");
        $sandbox->write('var/debug.log', "trace\n");
        $sandbox->git('add', '--force', 'var/debug.log');
        $sandbox->git('commit', '--quiet', '--message', 'chore: keep the log');
        $sandbox->write('.gitignore', '');

        $result = $sandbox->gateResult(new IgnoredFilesGate());

        self::assertSame([$this->differsFromIndex('.gitignore')], $result->findings);
    }

    public function testDeletingATrackedNestedGitignoreFromTheWorkingTreeFailsTheGateWithItsPath(): void
    {
        $sandbox = $this->repositoryIgnoring("*.log\n");
        $sandbox->write('docs/.gitignore', "draft.md\n");
        $sandbox->commitAll('docs: ignore drafts');
        unlink($sandbox->root.'/docs/.gitignore');

        $result = $sandbox->gateResult(new IgnoredFilesGate());

        self::assertSame([$this->differsFromIndex('docs/.gitignore')], $result->findings);
    }

    public function testAStagedRuleEditWithNothingIgnoredTrackedPasses(): void
    {
        $sandbox = $this->repositoryIgnoring("*.log\n");
        $sandbox->write('.gitignore', "*.log\n*.tmp\n");
        $sandbox->git('add', '.gitignore');

        $run = $sandbox->runGate(new IgnoredFilesGate());

        self::assertTrue($run->passed('ignored'), $run->output());
    }

    public function testARuleEditKeepingSizeAndModificationTimeFailsTheGateWhenGitDoesNotTrustCtime(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->startRepository();
        $sandbox->git('config', 'core.trustctime', 'false');
        $sandbox->write('.gitignore', "*.log\n");
        $stamp = 1_700_000_000;
        touch($sandbox->root.'/.gitignore', $stamp);
        $sandbox->commitAll('chore: ignore logs');
        $sandbox->write('.gitignore', "*.tmp\n");
        touch($sandbox->root.'/.gitignore', $stamp);

        $result = $sandbox->gateResult(new IgnoredFilesGate());

        self::assertSame('', $sandbox->git('diff', '--name-only')->output, 'git diff trusts the stat data in this scenario');
        self::assertSame([$this->differsFromIndex('.gitignore')], $result->findings);
    }

    public function testATrackedGitignoreReplacedByASymlinkInTheWorkingTreeFailsTheGate(): void
    {
        $sandbox = $this->repositoryIgnoring("*.log\n");
        $sandbox->write('rules.txt', "*.log\n");
        unlink($sandbox->root.'/.gitignore');
        $sandbox->symlink('.gitignore', 'rules.txt');

        $result = $sandbox->gateResult(new IgnoredFilesGate());

        self::assertSame([$this->differsFromIndex('.gitignore')], $result->findings);
    }

    public function testAnUntrackedSymlinkedGitignoreBesideATrackedFilePasses(): void
    {
        $sandbox = $this->repositoryIgnoring("*.log\n");
        $sandbox->write('rules.txt', "*.txt\n");
        $sandbox->write('logs/keep.txt', "kept\n");
        $sandbox->commitAll('chore: keep the logs folder');
        $sandbox->symlink('logs/.gitignore', '../rules.txt');

        $run = $sandbox->runGate(new IgnoredFilesGate());

        self::assertTrue($run->passed('ignored'), $run->output());
    }

    public function testATrackedSymlinkedGitignoreThatIsUnchangedPasses(): void
    {
        $sandbox = $this->repositoryIgnoring("*.log\n");
        $sandbox->write('rules.txt', "*.txt\n");
        $sandbox->write('logs/keep.txt', "kept\n");
        $sandbox->symlink('logs/.gitignore', '../rules.txt');
        $sandbox->commitAll('chore: link the logs rules');

        $run = $sandbox->runGate(new IgnoredFilesGate());

        self::assertTrue($run->passed('ignored'), $run->output());
    }

    public function testAnUnchangedGitignoreInAFolderNamedLikeAnOptionPasses(): void
    {
        $sandbox = $this->repositoryIgnoring("*.log\n");
        $sandbox->write('-rules/.gitignore', "draft.md\n");
        $sandbox->commitAll('chore: ignore drafts');

        $run = $sandbox->runGate(new IgnoredFilesGate());

        self::assertTrue($run->passed('ignored'), $run->output());
    }

    public function testAConflictedGitignoreGivesNoDifferingRuleFinding(): void
    {
        $sandbox = $this->repositoryIgnoring("*.log\n");
        $blob = trim($sandbox->git('hash-object', '-w', '.gitignore')->output);
        $entries = '0 '.str_repeat('0', strlen($blob))."\t.gitignore\n".implode('', array_map(static fn (int $stage): string => '100644 '.$blob.' '.$stage."\t.gitignore\n", [1, 2, 3]));
        $sandbox->runScript('printf %s "$ENTRIES" | git update-index --index-info', ['ENTRIES' => $entries]);

        $result = $sandbox->gateResult(new IgnoredFilesGate());

        self::assertSame([], $result->findings);
    }

    public function testAGitignoreCheckedOutWithCrlfLineEndingsByTheCommittedAttributesPasses(): void
    {
        $sandbox = $this->repositoryCheckingOutRulesWithCrlf();

        $result = $sandbox->gateResult(new IgnoredFilesGate());

        self::assertStringContainsString("\r\n", (string) file_get_contents($sandbox->root.'/.gitignore'));
        self::assertSame('', $sandbox->git('status', '--porcelain')->output, 'the checkout is clean');
        self::assertSame([], $result->findings);
    }

    public function testAnUnstagedRuleEditInACrlfCheckedOutGitignoreStillFailsTheGate(): void
    {
        $sandbox = $this->repositoryCheckingOutRulesWithCrlf();
        $sandbox->write('sub dir/.gitignore', "*.bak\r\n");

        $result = $sandbox->gateResult(new IgnoredFilesGate());

        self::assertSame([$this->differsFromIndex('sub dir/.gitignore')], $result->findings);
    }

    public function testAGitignoreWhoseIdentKeywordTheCommittedAttributesExpandedPasses(): void
    {
        $sandbox = $this->repositoryExpandingTheIdentKeywordInTheRules();

        $result = $sandbox->gateResult(new IgnoredFilesGate());

        self::assertStringContainsString('$Id: ', (string) file_get_contents($sandbox->root.'/.gitignore'));
        self::assertSame('', $sandbox->git('status', '--porcelain')->output, 'the checkout is clean');
        self::assertSame([], $result->findings);
    }

    public function testAnUnstagedRuleEditBesideAnExpandedIdentKeywordStillFailsTheGate(): void
    {
        $sandbox = $this->repositoryExpandingTheIdentKeywordInTheRules();
        $expanded = (string) file_get_contents($sandbox->root.'/.gitignore');
        $sandbox->write('.gitignore', str_replace('*.log', '*.bak', $expanded));

        $result = $sandbox->gateResult(new IgnoredFilesGate());

        self::assertSame([$this->differsFromIndex('.gitignore')], $result->findings);
    }

    public function testAnUnstagedEditFromAnIdentKeywordToAFreeFormIdStillFailsTheGate(): void
    {
        $sandbox = $this->repositoryIgnoring("# \$Id\$\n*.log\n");
        $sandbox->write('.gitignore', "# \$Id: x \$\n*.log\n");

        $result = $sandbox->gateResult(new IgnoredFilesGate());

        self::assertSame([$this->differsFromIndex('.gitignore')], $result->findings);
    }

    public function testAnUnstagedEditToAnObjectIdShapedIdKeywordWithoutTheIdentAttributeStillFailsTheGate(): void
    {
        $sandbox = $this->repositoryIgnoring("# \$Id\$\n*.log\n");
        $sandbox->write('.gitignore', '# $Id: '.str_repeat('a', 40)." \$\n*.bak\n");

        $result = $sandbox->gateResult(new IgnoredFilesGate());

        self::assertSame([$this->differsFromIndex('.gitignore')], $result->findings);
    }

    public function testAGitignoreCheckedOutInTheEncodingTheCommittedAttributesNamePasses(): void
    {
        $sandbox = $this->repositoryCheckingOutRulesInLatin1();

        $result = $sandbox->gateResult(new IgnoredFilesGate());

        self::assertSame("caf\xE9.log\n", file_get_contents($sandbox->root.'/.gitignore'));
        self::assertSame('', $sandbox->git('status', '--porcelain')->output, 'the checkout is clean');
        self::assertSame([], $result->findings);
    }

    public function testAnUnstagedRuleEditInAnEncodedGitignoreStillFailsTheGate(): void
    {
        $sandbox = $this->repositoryCheckingOutRulesInLatin1();
        $sandbox->write('.gitignore', "caf\xE9.bak\n");

        $result = $sandbox->gateResult(new IgnoredFilesGate());

        self::assertSame([$this->differsFromIndex('.gitignore')], $result->findings);
    }

    public function testAnEncodedGitignoreWhoseCharsetPhpCannotDecodeCountsAsDiffering(): void
    {
        $sandbox = $this->repositoryCheckingOutRulesInLatin1();
        $sandbox->write('.gitattributes', ".gitignore text working-tree-encoding=NO-SUCH-CHARSET\n");
        $sandbox->git('add', '.gitattributes');

        $result = $sandbox->gateResult(new IgnoredFilesGate());

        self::assertSame([$this->differsFromIndex('.gitignore')], $result->findings);
    }

    public function testAnUnstagedAttributesEditDoesNotChangeTheEncodingAnEncodedGitignoreIsReadIn(): void
    {
        $sandbox = $this->repositoryCheckingOutRulesInLatin1();
        $sandbox->write('.gitattributes', "*.md text\n");

        $result = $sandbox->gateResult(new IgnoredFilesGate());

        self::assertSame([], $result->findings);
    }

    public function testAGitlinkAtAGitignorePathGivesNoDifferingRuleFinding(): void
    {
        $sandbox = $this->repositoryWithAGitlinkNamedGitignore();

        $result = $sandbox->gateResult(new IgnoredFilesGate());

        self::assertSame([], $result->findings);
    }

    public function testAnUnstagedRuleEditBesideAGitlinkNamedGitignoreStillFailsTheGate(): void
    {
        $sandbox = $this->repositoryWithAGitlinkNamedGitignore();
        $sandbox->write('.gitignore', "*.bak\n");

        $result = $sandbox->gateResult(new IgnoredFilesGate());

        self::assertSame([$this->differsFromIndex('.gitignore')], $result->findings);
    }

    public function testRestoringADifferingRuleFileWithLiteralPathspecsKeepsTheEditOfASimilarlyNamedOne(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->startRepository();
        $sandbox->write('build[1]/.gitignore', "*.log\n");
        $sandbox->write('build1/.gitignore', "*.tmp\n");
        $sandbox->commitAll('chore: add the rules');
        $sandbox->write('build[1]/.gitignore', "*.bak\n");
        $sandbox->write('build1/.gitignore', "*.trace\n");

        $sandbox->git('--literal-pathspecs', 'restore', '--', 'build[1]/.gitignore');

        self::assertSame("*.trace\n", file_get_contents($sandbox->root.'/build1/.gitignore'));
        self::assertSame([$this->differsFromIndex('build1/.gitignore')], $sandbox->gateResult(new IgnoredFilesGate())->findings);
    }

    private function repositoryExpandingTheIdentKeywordInTheRules(): Sandbox
    {
        $sandbox = $this->emptySandbox();
        $sandbox->startRepository();
        $sandbox->write('.gitattributes', ".gitignore ident\n");
        $sandbox->write('.gitignore', "# \$Id\$\n*.log\n");
        $sandbox->commitAll('chore: expand the ident keyword in the rules');
        unlink($sandbox->root.'/.gitignore');
        $sandbox->git('checkout', '--', '.gitignore');

        return $sandbox;
    }

    private function repositoryCheckingOutRulesInLatin1(): Sandbox
    {
        $sandbox = $this->emptySandbox();
        $sandbox->startRepository();
        $sandbox->write('.gitattributes', ".gitignore text working-tree-encoding=ISO-8859-1\n");
        $sandbox->write('.gitignore', "caf\xE9.log\n");
        $sandbox->commitAll('chore: check out the rules in latin1');
        unlink($sandbox->root.'/.gitignore');
        $sandbox->git('checkout', '--', '.gitignore');

        return $sandbox;
    }

    private function repositoryWithAGitlinkNamedGitignore(): Sandbox
    {
        $sandbox = $this->repositoryIgnoring("*.log\n");
        $commit = trim($sandbox->git('rev-parse', 'HEAD')->output);
        $sandbox->git('update-index', '--add', '--cacheinfo', '160000,'.$commit.',sub/.gitignore');

        return $sandbox;
    }

    private function repositoryCheckingOutRulesWithCrlf(): Sandbox
    {
        $sandbox = $this->emptySandbox();
        $sandbox->startRepository();
        $sandbox->git('config', 'core.autocrlf', 'false');
        $sandbox->write('.gitattributes', ".gitignore text eol=crlf\n");
        $sandbox->write('.gitignore', "*.log\n");
        $sandbox->write('sub dir/.gitignore', "*.tmp\n");
        $sandbox->commitAll('chore: check out the rules with crlf');
        unlink($sandbox->root.'/.gitignore');
        unlink($sandbox->root.'/sub dir/.gitignore');
        $sandbox->git('checkout', '--', '.gitignore', 'sub dir/.gitignore');

        return $sandbox;
    }

    private function differsFromIndex(string $path): string
    {
        return $path.': working-tree .gitignore differs from the index; stage or revert it so the gate judges the rules that will be committed';
    }

    private function repositoryIgnoring(string $rules): Sandbox
    {
        $sandbox = $this->emptySandbox();
        $sandbox->startRepository();
        $sandbox->write('.gitignore', $rules);
        $sandbox->commitAll('chore: ignore build output');

        return $sandbox;
    }

    /**
     * @return list<string>
     */
    private function fullFindingsOf(GateResult $result): array
    {
        $report = json_decode($result->toFullJson(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($report);
        self::assertIsArray($report['findings'] ?? null);

        return array_values(array_filter($report['findings'], is_string(...)));
    }
}
