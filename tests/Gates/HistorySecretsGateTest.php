<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\CommitSubjectsGate;
use Intakt\Gates\HistorySecretsGate;
use Intakt\Gates\WorkingTreeSecretsGate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(HistorySecretsGate::class)]
#[UsesClass(WorkingTreeSecretsGate::class)]
#[UsesClass(CommitSubjectsGate::class)]
final class HistorySecretsGateTest extends SandboxTestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function foldersTheScannerSkips(): iterable
    {
        yield 'vendor' => ['vendor/package/settings.txt'];
        yield 'a nested vendor folder' => ['modules/Commerce/Cart/vendor/settings.txt'];
        yield 'node_modules' => ['node_modules/package/settings.txt'];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function filesTheScannersDefaultsSkip(): iterable
    {
        yield 'a vendor-like file name' => ['vendor_keys.php'];
        yield 'an svg image' => ['notes.svg'];
        yield 'a scanner configuration' => ['extra.gitleaks.toml'];
        yield 'a lock file' => ['package-lock.json'];
        yield 'a png image' => ['logo.png'];
    }

    #[DataProvider('filesTheScannersDefaultsSkip')]
    public function testASecretInAFileTheScannersDefaultsSkipFailsTheHistoryScan(string $path): void
    {
        $sandbox = $this->startedChange();
        $sandbox->write($path, 'token='.$this->fakeGithubToken()."\n");
        $sandbox->commitAll('feat: add a file');
        unlink($sandbox->root.'/'.$path);
        $sandbox->commitAll('fix: remove the file');

        $run = $sandbox->check('--only=secrets-history', '--base=main');

        self::assertTrue($run->failed('secrets-history'), $run->output());
        self::assertStringContainsString($path, $run->findingsOf('secrets-history'));
    }

    public function testASecretAddedOnlyInAMergeResolutionAndRemovedLaterFailsTheHistoryScan(): void
    {
        $sandbox = $this->startedChange();
        $sandbox->write('feature.txt', "feature\n");
        $sandbox->commitAll('feat: add a feature');
        $sandbox->git('switch', '--quiet', 'main');
        $sandbox->write('main.txt', "main\n");
        $sandbox->commitAll('chore: advance main');
        $sandbox->git('switch', '--quiet', 'change');
        $sandbox->git('merge', '--quiet', '--no-ff', '--no-commit', 'main');
        $sandbox->write('resolution.txt', 'token='.$this->fakeGithubToken()."\n");
        $sandbox->commitAll('chore: merge main into change');
        unlink($sandbox->root.'/resolution.txt');
        $sandbox->commitAll('fix: remove the resolution file');

        $run = $sandbox->check('--only=secrets-history', '--base=main');

        self::assertTrue($run->failed('secrets-history'), $run->output());
        self::assertStringContainsString('resolution.txt', $run->findingsOf('secrets-history'));
    }

    public function testAttributesThatMarkAFileAsBinaryDoNotHideItFromTheHistoryScan(): void
    {
        $sandbox = $this->startedChange();
        $sandbox->write('.gitattributes', "*.txt -diff\n*.dat binary\n");
        $sandbox->write('hidden.txt', 'token='.$this->fakeGithubToken()."\n");
        $sandbox->write('hidden.dat', 'token='.$this->fakeGithubToken()."\n");
        $sandbox->commitAll('feat: add files');
        unlink($sandbox->root.'/hidden.txt');
        unlink($sandbox->root.'/hidden.dat');
        $sandbox->commitAll('fix: remove the files');

        $run = $sandbox->check('--only=secrets-history', '--base=main');

        self::assertTrue($run->failed('secrets-history'), $run->output());
        self::assertStringContainsString('hidden.txt', $run->findingsOf('secrets-history'));
        self::assertStringContainsString('hidden.dat', $run->findingsOf('secrets-history'));
    }

    public function testTheHistoryScanUsesTheConfigurationOfTheBaseNotOfTheChange(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->startRepository();
        $sandbox->write('config/gates/gitleaks.toml', (string) file_get_contents(__DIR__.'/../../config/gates/gitleaks.toml'));
        $sandbox->commitAll('chore: start');
        $sandbox->git('switch', '--quiet', '--create', 'change');
        $sandbox->write('config/gates/gitleaks.toml', "title = \"allow all\"\n[allowlist]\nregexes = ['''.*''']\n");
        $sandbox->write('settings.txt', 'token='.$this->fakeGithubToken()."\n");
        $sandbox->commitAll('feat: add settings');

        $run = $sandbox->check('--only=secrets-history', '--base=main');

        self::assertTrue($run->failed('secrets-history'), $run->output());
        self::assertStringContainsString('settings.txt', $run->findingsOf('secrets-history'));
    }

    public function testTheChangeThatIntroducesTheGatesIsScannedWithItsOwnRules(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->startRepository();
        $sandbox->write('README.md', "start\n");
        $sandbox->commitAll('chore: start');
        $sandbox->git('switch', '--quiet', '--create', 'change');
        $sandbox->write('config/gates/gitleaks.toml', (string) file_get_contents(__DIR__.'/../../config/gates/gitleaks.toml'));
        $sandbox->write('settings.txt', 'token '.$this->fakeGithubToken()."\n");
        $sandbox->commitAll('feat: add the gates');

        $run = $sandbox->check('--only=secrets-history', '--base=main');

        self::assertTrue($run->failed('secrets-history'), $run->output());
        self::assertStringNotContainsString('base has no', $run->findingsOf('secrets-history'));
    }

    public function testABranchForkedBeforeTheGatesIsScannedWithTheRulesOfTheBaseTip(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->startRepository();
        $sandbox->write('README.md', "start\n");
        $sandbox->commitAll('chore: start');
        $sandbox->git('switch', '--quiet', '--create', 'change');
        $sandbox->git('switch', '--quiet', 'main');
        $sandbox->write('config/gates/gitleaks.toml', $this->scannerConfiguration());
        $sandbox->commitAll('build: add the gates');
        $sandbox->git('switch', '--quiet', 'change');
        $sandbox->write('config/gates/gitleaks.toml', "title = \"lax\"\n\n[[rules]]\nid = \"nothing\"\nregex = '''never-matches-anything'''\n");
        $sandbox->write('settings.txt', 'token '.$this->fakeGithubToken()."\n");
        $sandbox->commitAll('feat: add lax rules and a token');

        $run = $sandbox->check('--only=secrets-history', '--base=main');

        self::assertTrue($run->failed('secrets-history'), $run->output());
        self::assertStringNotContainsString('base has no', $run->findingsOf('secrets-history'));
    }

    public function testTheHistoryScanFailsClosedWhenTheBaseTipCannotBeRead(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->startRepository();
        $sandbox->write('README.md', "start\n");
        $sandbox->commitAll('chore: start');
        $tree = trim($sandbox->git('rev-parse', 'main^{tree}')->output);
        $sandbox->git('switch', '--quiet', '--create', 'change');
        $sandbox->write('config/gates/gitleaks.toml', $this->scannerConfiguration());
        $sandbox->commitAll('feat: add the gates');
        unlink($sandbox->root.'/.git/objects/'.substr($tree, 0, 2).'/'.substr($tree, 2));

        $run = $sandbox->check('--only=secrets-history', '--base=main');

        self::assertTrue($run->failed('secrets-history'), $run->output());
        self::assertStringContainsString('cannot read config/gates at the tip of the base', $run->findingsOf('secrets-history'));
    }

    public function testTheHistoryScanFailsWhenTheBaseHasGatesButNoScannerConfiguration(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->startRepository();
        $sandbox->write('config/gates/phpstan.neon', "parameters:\n    level: max\n");
        $sandbox->commitAll('chore: start');
        $sandbox->git('switch', '--quiet', '--create', 'change');
        $sandbox->write('config/gates/gitleaks.toml', (string) file_get_contents(__DIR__.'/../../config/gates/gitleaks.toml'));
        $sandbox->commitAll('feat: add the configuration');

        $run = $sandbox->check('--only=secrets-history', '--base=main');

        self::assertTrue($run->failed('secrets-history'), $run->output());
        self::assertStringContainsString('base has no config/gates/gitleaks.toml', $run->findingsOf('secrets-history'));
    }

    #[DataProvider('foldersTheScannerSkips')]
    public function testAFileAddedInAFolderTheScannerSkipsFailsTheHistoryScanEvenWhenRemovedAgain(string $path): void
    {
        $sandbox = $this->startedChange();
        $sandbox->write($path, 'token='.$this->fakeGithubToken()."\n");
        $sandbox->commitAll('feat: add a file');
        unlink($sandbox->root.'/'.$path);
        $sandbox->commitAll('fix: remove the file');

        $run = $sandbox->check('--only=secrets-history', '--base=main');

        self::assertTrue($run->failed('secrets-history'), $run->output());
        self::assertStringContainsString('folder the scanner skips', $run->findingsOf('secrets-history'));
    }

    public function testAnInlineAllowDirectiveInACommitOfTheChangeFailsTheHistoryScan(): void
    {
        $sandbox = $this->startedChange();
        $sandbox->write('settings.txt', 'token='.$this->fakeGithubToken().' # '.$this->inlineAllowDirective()."\n");
        $sandbox->commitAll('feat: add settings');
        unlink($sandbox->root.'/settings.txt');
        $sandbox->commitAll('fix: remove settings');

        $run = $sandbox->check('--only=secrets-history', '--base=main');

        self::assertTrue($run->failed('secrets-history'), $run->output());
        self::assertStringContainsString('inline allow directive', $run->findingsOf('secrets-history'));
    }

    public function testTheHistoryGatesSayTheyWereSkippedInATreeWithoutHistory(): void
    {
        $run = $this->sandboxWith('clean')->check('--only=secrets-history,commits');

        self::assertTrue($run->passed('secrets-history'), $run->output());
        self::assertStringContainsString('no git history', $run->noteOf('secrets-history'));
        self::assertStringContainsString('no git history', $run->noteOf('commits'));
    }

    public function testASecretAlreadyOnTheBaseBranchIsNotReportedAgainForTheChange(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->startRepository();
        $sandbox->write('README.md', "start\n");
        $sandbox->commitAll('chore: start');
        $sandbox->write('settings.txt', 'token='.$this->fakeGithubToken()."\n");
        $sandbox->commitAll('feat: add settings');
        unlink($sandbox->root.'/settings.txt');
        $sandbox->commitAll('fix: remove settings');
        $sandbox->git('switch', '--quiet', '--create', 'change');

        $run = $sandbox->check('--only=secrets,secrets-history', '--base=main');

        self::assertTrue($run->passed('secrets'), $run->output());
        self::assertSame('', $run->findingsOf('secrets-history'));
    }

    public function testASecretAddedAndDeletedWithinTheChangeIsFoundInItsHistory(): void
    {
        $sandbox = $this->startedChange();
        $sandbox->write('settings.txt', 'token='.$this->fakeGithubToken()."\n");
        $sandbox->commitAll('feat: add settings');
        unlink($sandbox->root.'/settings.txt');
        $sandbox->commitAll('fix: remove settings');

        $run = $sandbox->check('--only=secrets,secrets-history', '--base=main');

        self::assertTrue($run->passed('secrets'), $run->output());
        self::assertTrue($run->failed('secrets-history'), $run->output());
        self::assertStringContainsString('settings.txt', $run->findingsOf('secrets-history'));
    }

    public function testTheHistoryScanFailsWhenThereIsNoBaseToCompareWith(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->startRepository();
        $sandbox->commitAll('chore: start');

        $run = $sandbox->check('--only=secrets-history', '--base=missing');

        self::assertTrue($run->failed('secrets-history'), $run->output());
    }

    private function startedChange(): Sandbox
    {
        $sandbox = $this->emptySandbox();
        $sandbox->startRepository();
        $sandbox->write('README.md', "start\n");
        $sandbox->write('config/gates/gitleaks.toml', (string) file_get_contents(__DIR__.'/../../config/gates/gitleaks.toml'));
        $sandbox->commitAll('chore: start');
        $sandbox->git('switch', '--quiet', '--create', 'change');

        return $sandbox;
    }

    private function inlineAllowDirective(): string
    {
        return "gitleaks\x3aallow";
    }

    private function fakeGithubToken(): string
    {
        return 'ghp_'.strrev('u4Ge2As0Py5hJ1Fc6Dw3Zb8Nt4Km9Vx2Lq7R');
    }

    private function scannerConfiguration(): string
    {
        return (string) file_get_contents(__DIR__.'/../../config/gates/gitleaks.toml');
    }
}
