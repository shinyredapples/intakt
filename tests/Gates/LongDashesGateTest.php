<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\LongDashesGate;
use Intakt\Gates\Workspace;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(LongDashesGate::class)]
#[UsesClass(Workspace::class)]
final class LongDashesGateTest extends SandboxTestCase
{
    private const string EM_DASH = "\u{2014}";
    private const string HORIZONTAL_BAR = "\u{2015}";
    private const string TWO_EM_DASH = "\u{2E3A}";

    public function testTextWithoutALongDashPasses(): void
    {
        $sandbox = $this->changeWithFile('docs/guide.md', "# Guide\n\nA plain - hyphen and an en dash \u{2013} are fine.\n");
        $sandbox->commitAll("docs: write the guide\n\nA body with a plain hyphen - only.");

        $run = $sandbox->runGate(new LongDashesGate());

        self::assertTrue($run->passed('dashes'), $run->output());
    }

    public function testAnEmDashInAMarkdownFileFailsNamingTheFileAndLine(): void
    {
        $sandbox = $this->changeWithFile('docs/guide.md', "# Guide\n\nFirst line.\nA claim ".self::EM_DASH." and its reason.\n");

        $run = $sandbox->runGate(new LongDashesGate());

        self::assertTrue($run->failed('dashes'), $run->output());
        self::assertStringContainsString('docs/guide.md:4: U+2014', $run->findingsOf('dashes'));
    }

    public function testAnEmDashInAPhpCommentFailsNamingTheFileAndLine(): void
    {
        $sandbox = $this->changeWithFile('src/Report.php', "<?php\n\n// A rule ".self::EM_DASH." see the link\nfinal class Report {}\n");

        $run = $sandbox->runGate(new LongDashesGate());

        self::assertTrue($run->failed('dashes'), $run->output());
        self::assertStringContainsString('src/Report.php:3: U+2014', $run->findingsOf('dashes'));
    }

    public function testAnEmDashInAPhpStringFailsNamingTheFileAndLine(): void
    {
        $sandbox = $this->changeWithFile('src/Report.php', "<?php\n\nfinal class Report\n{\n    public const string TITLE = 'Sales ".self::EM_DASH." monthly';\n}\n");

        $run = $sandbox->runGate(new LongDashesGate());

        self::assertTrue($run->failed('dashes'), $run->output());
        self::assertStringContainsString('src/Report.php:5: U+2014', $run->findingsOf('dashes'));
    }

    public function testAHorizontalBarFails(): void
    {
        $sandbox = $this->changeWithFile('config/services.yaml', 'title: "a '.self::HORIZONTAL_BAR." b\"\n");

        $run = $sandbox->runGate(new LongDashesGate());

        self::assertStringContainsString('config/services.yaml:1: U+2015', $run->findingsOf('dashes'));
    }

    public function testATwoEmDashFails(): void
    {
        $sandbox = $this->changeWithFile('.env', 'APP_TITLE="a '.self::TWO_EM_DASH." b\"\n");

        $run = $sandbox->runGate(new LongDashesGate());

        self::assertStringContainsString('.env:1: U+2E3A', $run->findingsOf('dashes'));
    }

    public function testEveryLongDashOnALineIsNamed(): void
    {
        $sandbox = $this->changeWithFile('notes.txt', 'a '.self::TWO_EM_DASH.' b '.self::EM_DASH." c\n");

        $run = $sandbox->runGate(new LongDashesGate());

        self::assertStringContainsString('notes.txt:1: U+2014, U+2E3A', $run->findingsOf('dashes'));
    }

    public function testAFileInASpikeOrFixtureFolderIsCheckedToo(): void
    {
        $sandbox = $this->changeWithFile('spikes/cache/README.md', 'Result '.self::EM_DASH." fast\n");
        $sandbox->write('tests/Gates/Fixtures/clean/src/Report.php.fixture', '// '.self::EM_DASH."\n");

        $run = $sandbox->runGate(new LongDashesGate());

        self::assertStringContainsString('spikes/cache/README.md:1: U+2014', $run->findingsOf('dashes'));
        self::assertStringContainsString('tests/Gates/Fixtures/clean/src/Report.php.fixture:1: U+2014', $run->findingsOf('dashes'));
    }

    public function testABinaryFileIsNotRead(): void
    {
        $sandbox = $this->changeWithFile('public/logo.png', "\x89PNG\0\0".self::EM_DASH."\0");

        $run = $sandbox->runGate(new LongDashesGate());

        self::assertTrue($run->passed('dashes'), $run->output());
    }

    public function testAnIgnoredFileIsNotRead(): void
    {
        $sandbox = $this->changeWithFile('.gitignore', "/PR.md\n");
        $sandbox->write('PR.md', 'Title '.self::EM_DASH." draft\n");

        $run = $sandbox->runGate(new LongDashesGate());

        self::assertTrue($run->passed('dashes'), $run->output());
    }

    public function testAnEmDashInACommitSubjectFailsNamingTheCommit(): void
    {
        $sandbox = $this->startedChange();
        $sandbox->commitAll('feat: place an order '.self::EM_DASH.' fast');
        $commit = $this->shortHashOf($sandbox, 'HEAD');

        $run = $sandbox->runGate(new LongDashesGate());

        self::assertTrue($run->failed('dashes'), $run->output());
        self::assertStringContainsString('commit '.$commit.' subject: U+2014', $run->findingsOf('dashes'));
    }

    public function testAnEmDashInACommitBodyFailsNamingTheCommitAndLine(): void
    {
        $sandbox = $this->startedChange();
        $sandbox->commitAll("feat: place an order\n\nWhy it matters:\nit is fast ".self::EM_DASH.' and safe.');
        $commit = $this->shortHashOf($sandbox, 'HEAD');

        $run = $sandbox->runGate(new LongDashesGate());

        self::assertTrue($run->failed('dashes'), $run->output());
        self::assertStringContainsString('commit '.$commit.' message line 4: U+2014', $run->findingsOf('dashes'));
    }

    public function testCommitsAlreadyOnTheBaseBranchAreNotChecked(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->startRepository();
        $sandbox->commitAll('chore: start '.self::EM_DASH.' early');
        $sandbox->git('switch', '--quiet', '--create', 'change');
        $sandbox->commitAll('feat: new work');

        $run = $sandbox->runGate(new LongDashesGate());

        self::assertTrue($run->passed('dashes'), $run->output());
    }

    public function testTheGateFailsWhenThereIsNoBaseBranch(): void
    {
        $sandbox = $this->startedChange();

        $run = $sandbox->check('--only=dashes', '--base=missing');

        self::assertTrue($run->failed('dashes'), $run->output());
        self::assertStringContainsString('no base branch found', $run->findingsOf('dashes'));
    }

    public function testATreeWithoutHistoryHasItsFilesCheckedAndSaysTheCommitsWereSkipped(): void
    {
        $clean = $this->emptySandbox();
        $clean->write('README.md', "# Plain\n");
        $dashed = $this->emptySandbox();
        $dashed->write('README.md', 'Plain '.self::EM_DASH." text\n");

        $cleanRun = $clean->runGate(new LongDashesGate());
        $dashedRun = $dashed->runGate(new LongDashesGate());

        self::assertTrue($cleanRun->passed('dashes'), $cleanRun->output());
        self::assertStringContainsString('commits skipped, no git history', $cleanRun->noteOf('dashes'));
        self::assertStringContainsString('README.md:1: U+2014', $dashedRun->findingsOf('dashes'));
    }

    private function changeWithFile(string $path, string $contents): Sandbox
    {
        $sandbox = $this->startedChange();
        $sandbox->write($path, $contents);
        $sandbox->commitAll('feat: add a file');

        return $sandbox;
    }

    private function startedChange(): Sandbox
    {
        $sandbox = $this->emptySandbox();
        $sandbox->startRepository();
        $sandbox->commitAll('chore: start');
        $sandbox->git('switch', '--quiet', '--create', 'change');

        return $sandbox;
    }

    private function shortHashOf(Sandbox $sandbox, string $revision): string
    {
        return substr(trim($sandbox->git('rev-parse', $revision)->output), 0, 8);
    }
}
