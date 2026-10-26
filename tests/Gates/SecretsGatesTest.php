<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use PHPUnit\Framework\Attributes\DataProvider;

final class SecretsGatesTest extends SandboxTestCase
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
    public static function locationsThatOnceWereAllowlisted(): iterable
    {
        foreach (['var/cache/dump.txt', '.evidence/run.txt'] as $path) {
            yield $path => [$path];
        }
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
    public function testAPlantedSecretInAFileTheScannersDefaultsSkipFailsTheScan(string $path): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write($path, 'token='.$this->fakeGithubToken()."\n");

        $run = $sandbox->check('--only=secrets');

        self::assertTrue($run->failed('secrets'), $run->output());
        self::assertStringContainsString($path, $run->findingsOf('secrets'));
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

    public function testATokenInANestedCopyOfTheScannerConfigurationFailsTheScan(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('spikes/throwaway/config/gates/gitleaks.toml', 'token='.$this->fakeGithubToken()."\n");

        $run = $sandbox->check('--only=secrets');

        self::assertTrue($run->failed('secrets'), $run->output());
        self::assertStringContainsString('spikes/throwaway/config/gates/gitleaks.toml', $run->findingsOf('secrets'));
    }

    public function testARealSecretContainingADummyValueStillFailsTheScan(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('settings.php', "<?php\n\$".strrev('yeKipa')." = 'Zx9Kq2Lm7Vb4Tn".strrev('5-terces-egrup')."';\n");
        $sandbox->write('stripe.txt', 'publishable pk_test_abc and a leaked value '.$this->fakeGithubToken()."pk_test_abc\n");

        $run = $sandbox->check('--only=secrets');

        self::assertTrue($run->failed('secrets'), $run->output());
        self::assertStringContainsString('settings.php', $run->findingsOf('secrets'));
    }

    public function testTheScannerConfigurationPassesItsOwnScan(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('config/gates/gitleaks.toml', $this->scannerConfiguration());

        $run = $sandbox->check('--only=secrets');

        self::assertTrue($run->passed('secrets'), $run->output());
    }

    public function testATokenAddedToTheScannerConfigurationIsCaught(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('config/gates/gitleaks.toml', $this->scannerConfiguration().'token = "'.$this->fakeGithubToken()."\"\n");

        $run = $sandbox->check('--only=secrets');

        self::assertTrue($run->failed('secrets'), $run->output());
        self::assertStringContainsString('config/gates/gitleaks.toml', $run->findingsOf('secrets'));
    }

    public function testATokenAsAListEntryInTheScannerConfigurationIsCaught(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('config/gates/gitleaks.toml', $this->scannerConfiguration().'stopwords = [\n    "'.$this->fakeGithubToken()."\",\n]\n");

        self::assertTrue($sandbox->check('--only=secrets')->failed('secrets'));
    }

    public function testATokenOnARuleRegexLineInAnotherFileIsCaught(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('rules.toml', "regex = '''".$this->fakeGithubToken()."'''\n");

        self::assertTrue($sandbox->check('--only=secrets')->failed('secrets'));
    }

    public function testATokenOnARuleRegexLineInTheScannerConfigurationIsCaught(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('config/gates/gitleaks.toml', $this->scannerConfiguration()."\n[[rules]]\nid = \"planted\"\nregex = '''".$this->fakeGithubToken()."'''\n");

        self::assertTrue($sandbox->check('--only=secrets')->failed('secrets'));
    }

    public function testATokenAsAQuotedEntryOfAJsonListIsCaught(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('settings.json', "{\"tokens\": [\n    \"".$this->fakeGithubToken()."\",\n    \"other\"\n]}\n");

        self::assertTrue($sandbox->check('--only=secrets')->failed('secrets'));
    }

    public function testATokenAsAQuotedEntryOfAPhpArrayIsCaught(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('settings.php', "<?php\n\nreturn [\n    \"".$this->fakeGithubToken()."\",\n];\n");

        self::assertTrue($sandbox->check('--only=secrets')->failed('secrets'));
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

    public function testAScannerNamedByTheOverrideIsVersionCheckedToo(): void
    {
        $stubs = $this->emptySandbox();
        $stubs->write('bin/gitleaks', "#!/bin/sh\ncase \"\$1\" in version) echo 1.0.0;; esac\n");
        chmod($stubs->root.'/bin/gitleaks', 0o755);
        putenv('INTAKT_GITLEAKS='.$stubs->root.'/bin/gitleaks');

        try {
            $run = $this->sandboxWith('clean')->check('--only=secrets');
        } finally {
            putenv('INTAKT_GITLEAKS');
        }

        self::assertTrue($run->failed('secrets'), $run->output());
        self::assertStringContainsString('gitleaks 8.30.1 is required', $run->findingsOf('secrets'));
    }

    public function testAFileTheScanCannotCopyFailsTheScan(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->startRepository();
        $sandbox->write('locked.txt', "locked\n");
        $sandbox->commitAll('chore: start');
        chmod($sandbox->root.'/locked.txt', 0o000);

        try {
            $run = $sandbox->check('--only=secrets');
        } finally {
            chmod($sandbox->root.'/locked.txt', 0o644);
        }

        self::assertTrue($run->failed('secrets'), $run->output());
        self::assertStringContainsString('cannot copy', $run->findingsOf('secrets'));
    }

    public function testFilesComposerInstalledIntoVendorOutsideARepositoryAreNotScanned(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('vendor/package/tests/key.txt', 'token='.$this->fakeGithubToken()."\n");
        $sandbox->write('README.md', "start\n");

        self::assertTrue($sandbox->check('--only=secrets')->passed('secrets'));
    }

    public function testAScannerOtherThanThePinnedVersionIsRefused(): void
    {
        $run = $this->checkWithScannerReporting('1.0.0');

        self::assertTrue($run->failed('secrets'), $run->output());
        self::assertStringContainsString('gitleaks 8.30.1 is required', $run->findingsOf('secrets'));
    }

    public function testTheScannerAtThePinnedVersionIsAccepted(): void
    {
        self::assertTrue($this->checkWithScannerReporting('8.30.1')->passed('secrets'));
    }

    public function testThePinnedVersionMatchesTheOneTheWorkflowInstalls(): void
    {
        self::assertMatchesRegularExpression('/^version=8\.30\.1$/m', Workflow::runOf('Install the pinned gitleaks'));
    }

    public function testAPlantedSecretInTheWorkingTreeFailsTheScan(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('src/settings.txt', 'token='.$this->fakeGithubToken()."\n");

        $run = $sandbox->check('--only=secrets');

        self::assertSame(1, $run->exitCode(), $run->output());
        self::assertStringContainsString('src/settings.txt', $run->findingsOf('secrets'));
        self::assertStringNotContainsString($this->fakeGithubToken(), $run->output());
    }

    public function testAPlantedSecretInsideSpikesStillFailsTheScan(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('spikes/throwaway/settings.txt', 'token='.$this->fakeGithubToken()."\n");

        self::assertTrue($sandbox->check('--only=secrets')->failed('secrets'));
    }

    #[DataProvider('locationsThatOnceWereAllowlisted')]
    public function testAPlantedSecretInAnyFormerlyAllowlistedLocationStillFailsTheScan(string $path): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write($path, 'token='.$this->fakeGithubToken()."\n");

        $run = $sandbox->check('--only=secrets');

        self::assertTrue($run->failed('secrets'), $run->output());
        self::assertStringContainsString($path, $run->findingsOf('secrets'));
    }

    #[DataProvider('foldersTheScannerSkips')]
    public function testATrackedFileInAFolderTheScannerSkipsFailsTheScan(string $path): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->startRepository();
        $sandbox->write($path, 'token='.$this->fakeGithubToken()."\n");
        $sandbox->git('add', '--force', '--all');

        $run = $sandbox->check('--only=secrets');

        self::assertTrue($run->failed('secrets'), $run->output());
        self::assertStringContainsString($path, $run->findingsOf('secrets'));
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

    public function testAnInlineAllowDirectiveDoesNotHideASecret(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('src/settings.txt', 'token='.$this->fakeGithubToken().' # '.$this->inlineAllowDirective()."\n");

        $run = $sandbox->check('--only=secrets');

        self::assertTrue($run->failed('secrets'), $run->output());
        self::assertStringContainsString('inline allow directive', $run->findingsOf('secrets'));
    }

    public function testAnIgnoreFileDoesNotHideASecret(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('src/settings.txt', 'token='.$this->fakeGithubToken()."\n");
        $sandbox->write('.gitleaksignore', "src/settings.txt:github-pat:1\n");

        $run = $sandbox->check('--only=secrets');

        self::assertTrue($run->failed('secrets'), $run->output());
        self::assertStringContainsString('.gitleaksignore', $run->findingsOf('secrets'));
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

    public function testAnIgnoredAndUntrackedDependencyFolderIsNotPartOfTheChange(): void
    {
        $sandbox = $this->sandboxWith('clean');
        $sandbox->startRepository();
        $sandbox->write('.gitignore', "vendor/\n");
        $sandbox->commitAll('chore: start');
        $sandbox->write('vendor/package/settings.txt', 'token='.$this->fakeGithubToken()."\n");

        self::assertTrue($sandbox->check('--only=secrets')->passed('secrets'));
    }

    public function testAnUntrackedFileThatIsNotIgnoredIsScanned(): void
    {
        $sandbox = $this->sandboxWith('clean');
        $sandbox->startRepository();
        $sandbox->commitAll('chore: start');
        $sandbox->write('notes.svg', 'token='.$this->fakeGithubToken()."\n");

        self::assertTrue($sandbox->check('--only=secrets')->failed('secrets'));
    }

    public function testATreeWithoutSecretsPassesTheScan(): void
    {
        $sandbox = $this->sandboxWith('clean');

        self::assertTrue($sandbox->check('--only=secrets')->passed('secrets'));
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

    private function checkWithScannerReporting(string $version): CheckRun
    {
        $stubs = $this->emptySandbox();
        $stubs->write('bin/gitleaks', "#!/bin/sh\ncase \"\$1\" in version) echo ".$version.";; esac\n");
        chmod($stubs->root.'/bin/gitleaks', 0o755);
        $path = (string) getenv('PATH');
        putenv('INTAKT_GITLEAKS');
        putenv('PATH='.$stubs->root.'/bin:'.$path);

        try {
            return $this->sandboxWith('clean')->check('--only=secrets');
        } finally {
            putenv('PATH='.$path);
        }
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
