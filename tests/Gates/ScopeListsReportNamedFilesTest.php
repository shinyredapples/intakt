<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\ProcessResult;
use Intakt\Gates\ProcessRunner;
use Intakt\Gates\Scope;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(Scope::class)]
#[UsesClass(ProcessResult::class)]
#[UsesClass(ProcessRunner::class)]
final class ScopeListsReportNamedFilesTest extends SandboxTestCase
{
    private const string REPORT_NAME = 'report-0123456789abcdef.json';

    public function testAnUntrackedFileNamedAfterTheHashOfItsContentIsStillListed(): void
    {
        $tree = $this->emptySandbox();
        $tree->startRepository();
        $content = "ordinary fixture\n";
        $name = 'report-'.substr(hash('sha256', $content), 0, 16).'.json';
        $tree->write($name, $content);
        $scope = new Scope($tree->root, new ProcessRunner());

        self::assertContains($name, $scope->untrackedPaths());
        self::assertContains($name, $scope->repositoryFiles());
    }

    public function testAnUntrackedFileWithAReportNameIsStillListedUnderTheProjectsIgnoreRules(): void
    {
        $tree = $this->emptySandbox();
        $tree->startRepository();
        $tree->write('.gitignore', (string) file_get_contents(dirname(__DIR__, 2).'/.gitignore'));
        $tree->write(self::REPORT_NAME, "ordinary fixture\n");
        $scope = new Scope($tree->root, new ProcessRunner());

        self::assertContains(self::REPORT_NAME, $scope->untrackedPaths());
        self::assertContains(self::REPORT_NAME, $scope->repositoryFiles());
    }

    public function testAnUnreadableFileWithAReportNameIsListedWithoutPrintingAnything(): void
    {
        $this->skipWhenPermissionsAreIgnored();
        $tree = $this->emptySandbox();
        $tree->write(self::REPORT_NAME, 'ordinary fixture');
        chmod($tree->root.'/'.self::REPORT_NAME, 0o000);
        $shown = ini_set('display_errors', '1');
        ob_start();

        try {
            $files = new Scope($tree->root, new ProcessRunner())->repositoryFiles();
        } finally {
            $printed = ob_get_clean();
            ini_set('display_errors', false === $shown ? '' : $shown);
            chmod($tree->root.'/'.self::REPORT_NAME, 0o600);
        }

        self::assertContains(self::REPORT_NAME, $files);
        self::assertSame('', $printed);
    }
}
