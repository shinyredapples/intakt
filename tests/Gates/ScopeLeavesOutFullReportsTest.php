<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\FullReport;
use Intakt\Gates\GateReport;
use Intakt\Gates\GateResult;
use Intakt\Gates\LongDashesGate;
use Intakt\Gates\Mode;
use Intakt\Gates\ProcessResult;
use Intakt\Gates\ProcessRunner;
use Intakt\Gates\Scope;
use Intakt\Gates\ScratchDirectory;
use Intakt\Gates\Workspace;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(Scope::class)]
#[UsesClass(FullReport::class)]
#[UsesClass(GateReport::class)]
#[UsesClass(GateResult::class)]
#[UsesClass(LongDashesGate::class)]
#[UsesClass(ProcessResult::class)]
#[UsesClass(ProcessRunner::class)]
#[UsesClass(ScratchDirectory::class)]
#[UsesClass(Workspace::class)]
final class ScopeLeavesOutFullReportsTest extends SandboxTestCase
{
    private const string EM_DASH = "\u{2014}";

    public function testAFullReportInATreeWithoutGitIsNoInputOfTheNextCheck(): void
    {
        $tree = $this->emptySandbox();
        $this->writeReportInto($tree);

        $result = new LongDashesGate()->run($this->workspaceOf($tree));

        self::assertTrue($result->isPassing(), implode("\n", $result->findings));
    }

    public function testAnOrdinaryFileWithAReportNameInATreeWithoutGitIsStillChecked(): void
    {
        $tree = $this->emptySandbox();
        $tree->write('report-0123456789abcdef.json', '{"ordinary":"fixture"}');

        $files = new Scope($tree->root, new ProcessRunner())->repositoryFiles();

        self::assertContains('report-0123456789abcdef.json', $files);
    }

    public function testAnOrdinaryUntrackedFileWithAReportNameInAGitCheckoutIsStillChecked(): void
    {
        $tree = $this->emptySandbox();
        $tree->startRepository();
        $tree->write('report-0123456789abcdef.json', '{"ordinary":"fixture"}');
        $scope = new Scope($tree->root, new ProcessRunner());

        self::assertContains('report-0123456789abcdef.json', $scope->repositoryFiles());
        self::assertContains('report-0123456789abcdef.json', $scope->untrackedPaths());
    }

    public function testAFileThatOnlyLooksLikeAReportIsStillChecked(): void
    {
        $tree = $this->emptySandbox();
        $tree->write('report-release.json', '{"note":"a'.self::EM_DASH.'b"}'."\n");
        $tree->write('docs/report-0123456789abcdef.json', '{"note":"a'.self::EM_DASH.'b"}'."\n");

        $files = new Scope($tree->root, new ProcessRunner())->repositoryFiles();

        self::assertContains('report-release.json', $files);
        self::assertContains('docs/report-0123456789abcdef.json', $files);
    }

    public function testANameThatEndsInANewlineAfterTheReportNameIsStillChecked(): void
    {
        $tree = $this->emptySandbox();
        $tree->write("report-0123456789abcdef.json\n", '{"fixture":"not a report"}');

        $files = new Scope($tree->root, new ProcessRunner())->repositoryFiles();

        self::assertContains("report-0123456789abcdef.json\n", $files);
    }

    public function testAnOrdinaryFileInsideAReportShapedFolderOfAGitCheckoutIsStillChecked(): void
    {
        $tree = $this->emptySandbox();
        $tree->startRepository();
        $tree->write('.gitignore', (string) file_get_contents(dirname(__DIR__, 2).'/.gitignore'));
        $tree->write('report-0123456789abcdef.json/input.php', "<?php\n");

        $files = new Scope($tree->root, new ProcessRunner())->repositoryFiles();

        self::assertContains('report-0123456789abcdef.json/input.php', $files);
    }

    public function testALeftOverReportInAGitCheckoutWithoutTheIgnoreRuleIsNoInputOfTheNextCheck(): void
    {
        $tree = $this->emptySandbox();
        $tree->startRepository();
        $name = $this->writeReportInto($tree);
        $scope = new Scope($tree->root, new ProcessRunner());

        self::assertNotContains($name, $scope->repositoryFiles());
        self::assertNotContains($name, $scope->untrackedPaths());
    }

    public function testACommittedFileWithAReportNameIsStillChecked(): void
    {
        $tree = $this->emptySandbox();
        $tree->startRepository();
        $tree->write('report-0123456789abcdef.json', '{"note":"a'.self::EM_DASH.'b"}'."\n");
        $tree->commitAll('Add a file with a report name');

        $files = new Scope($tree->root, new ProcessRunner())->repositoryFiles();

        self::assertContains('report-0123456789abcdef.json', $files);
    }

    private function writeReportInto(Sandbox $tree): string
    {
        $finding = 'note'.self::EM_DASH.'.md:1: U+2014';
        $line = GateReport::fromChild('dashes', new ProcessResult(1, GateResult::withFindings('dashes', [$finding])->toJson()."\n", ''));
        $name = FullReport::start()->writeTo($tree->root, ['result' => 'fail', 'failed' => ['dashes']], [$line]);
        self::assertIsString($name);

        return $name;
    }

    private function workspaceOf(Sandbox $tree): Workspace
    {
        return new Workspace($tree->root, dirname(__DIR__, 2), Mode::ChangedFilesOnly, null);
    }
}
