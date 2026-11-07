<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\WorkingTreeSecretsGate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

// A sandbox and a scan per test, because a shared sandbox would let one planted secret fail the next test's clean scan, and most of a scan's second is the gate starting gitleaks twice (version check, then scan), which is the code under test.
#[CoversClass(WorkingTreeSecretsGate::class)]
final class WorkingTreeSecretsGateTest extends SandboxTestCase
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

    public function testAShortLivedBedrockKeyIsCaught(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('key.txt', 'key = '.$this->bedrockPrefix().strrev('3D%Q9xVb2Kq7Ld4Mf8Nh1Rj5Tz0Wy6Pc')."\n");

        self::assertTrue($sandbox->check('--only=secrets')->failed('secrets'));
    }

    public function testTheBarePrefixOfABedrockKeyIsNotAKey(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('prefix.txt', "regex = '''".$this->bedrockPrefix()."'''\n");

        self::assertTrue($sandbox->check('--only=secrets')->passed('secrets'));
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

    public function testThePinnedVersionMatchesTheOneTheCiImageInstalls(): void
    {
        self::assertMatchesRegularExpression('#^FROM ghcr\.io/gitleaks/gitleaks:v8\.30\.1@sha256:[0-9a-f]{64} AS gitleaks$#m', Workflow::ciImageDefinition());
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

    private function bedrockPrefix(): string
    {
        return 'bedrock-api-key-'.base64_encode('bedrock.amazonaws.com');
    }
}
