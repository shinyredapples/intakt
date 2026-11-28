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
