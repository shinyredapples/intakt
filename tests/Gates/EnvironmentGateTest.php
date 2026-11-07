<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\EnvironmentGate;
use Intakt\Gates\GateResult;
use Intakt\Gates\Mode;
use Intakt\Gates\PhpRuntime;
use Intakt\Gates\Workspace;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Component\Process\Process;

#[CoversClass(EnvironmentGate::class)]
#[CoversClass(PhpRuntime::class)]
#[UsesClass(GateResult::class)]
#[UsesClass(Workspace::class)]
final class EnvironmentGateTest extends SandboxTestCase
{
    private const string MANIFEST = '{"require":{"php":">=8.5","ext-ctype":"*","ext-Intl":"*","symfony/console":"8.0"},"config":{"platform":{"php":"8.5.12"}}}';
    private const string CHECK_INI = "zend.assertions=1\nerror_reporting=E_ALL\nmemory_limit=-1\n";

    public function testARuntimeMatchingTheProfilePasses(): void
    {
        $result = $this->checked($this->runtime());

        self::assertSame([], $result->findings);
    }

    public function testTheRunningPhpPassesWhenItLoadsTheProfile(): void
    {
        $result = $this->checked(PhpRuntime::current(), '{"require":{"ext-ctype":"*"}}', 'memory_limit='.ini_get('memory_limit')."\n");

        self::assertSame([], $result->findings);
    }

    public function testAMissingExtensionFailsAndNamesIt(): void
    {
        $result = $this->checked($this->runtime(extensions: ['ctype']));

        self::assertSame(['PHP extension Intl is required by composer.json but not loaded'], $result->findings);
    }

    public function testAnOlderPhpFailsAndNamesBothVersions(): void
    {
        $result = $this->checked($this->runtime(version: '8.4.3'));

        self::assertSame(['PHP 8.4.3 is older than the platform version 8.5.12 in composer.json'], $result->findings);
    }

    public function testDisabledAssertionsFailAndNameTheSetting(): void
    {
        $result = $this->checked($this->runtime(settings: ['zend.assertions' => '-1']));

        self::assertSame('PHP setting zend.assertions is -1 but config/gates/php/check.ini says 1', $result->findings[0]);
    }

    public function testErrorReportingIsComparedAsANumber(): void
    {
        $result = $this->checked($this->runtime(settings: ['error_reporting' => '22527']));

        self::assertSame('PHP setting error_reporting is 22527 but config/gates/php/check.ini says E_ALL', $result->findings[0]);
    }

    public function testABooleanWordInTheCheckIniMatchesTheNumberPhpReports(): void
    {
        $result = $this->checked($this->runtime(settings: ['display_errors' => '1', 'assert.exception' => '0']), self::MANIFEST, "display_errors=On\nassert.exception=Off\n");

        self::assertSame([], $result->findings);
    }

    public function testABooleanSettingThatDiffersFailsAndShowsTheValueAsWritten(): void
    {
        $result = $this->checked($this->runtime(settings: ['display_errors' => '']), self::MANIFEST, "display_errors=On\n");

        self::assertSame('PHP setting display_errors is  but config/gates/php/check.ini says On', $result->findings[0]);
    }

    public function testAnErrorReportingExpressionIsComparedAsTheNumberItStandsFor(): void
    {
        $result = $this->checked($this->runtime(settings: ['error_reporting' => (string) (E_ALL & ~E_DEPRECATED)]), self::MANIFEST, "error_reporting=E_ALL & ~E_DEPRECATED\n");

        self::assertSame([], $result->findings);
    }

    public function testTheRunningPhpPassesWhenItLoadsAProfileWithBooleanWordsAndExpressions(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('composer.json', '{"require":{}}');
        $sandbox->write('config/gates/php/check.ini', "display_errors=On\nassert.exception=On\nerror_reporting=E_ALL & ~E_DEPRECATED\nmemory_limit=-1\n");
        $process = new Process(
            [PHP_BINARY, '-r', 'require $argv[1]; echo new Intakt\Gates\EnvironmentGate(Intakt\Gates\PhpRuntime::current())->run(new Intakt\Gates\Workspace($argv[2], $argv[2], Intakt\Gates\Mode::Full, null))->toJson();', dirname(__DIR__, 2).'/vendor/autoload.php', $sandbox->root],
            env: ['PHP_INI_SCAN_DIR' => getenv('PHP_INI_SCAN_DIR').':'.$sandbox->root.'/config/gates/php'],
        );
        $process->mustRun();

        self::assertSame('{"gate":"environment","ok":true,"findings":[],"omitted":0}', $process->getOutput());
    }

    #[TestWith(['filter'])]
    #[TestWith(['tokenizer'])]
    #[TestWith(['fileinfo'])]
    #[TestWith(['xml'])]
    public function testTheRepositoryProfileRequiresTheExtension(string $extension): void
    {
        $root = dirname(__DIR__, 2);
        $runtime = $this->runtime(extensions: array_values(array_diff(array_map(strtolower(...), get_loaded_extensions()), [$extension])));

        $result = new EnvironmentGate($runtime)->run(new Workspace($root, $root, Mode::Full, null));

        self::assertContains('PHP extension '.$extension.' is required by composer.json but not loaded', $result->findings);
    }

    public function testAPhpWithoutXmlAndFileinfoFailsNamingBoth(): void
    {
        $root = dirname(__DIR__, 2);
        $runtime = $this->runtime(extensions: array_values(array_diff(array_map(strtolower(...), get_loaded_extensions()), ['xml', 'fileinfo'])));

        $result = new EnvironmentGate($runtime)->run(new Workspace($root, $root, Mode::Full, null));

        self::assertFalse($result->isPassing());
        self::assertContains('PHP extension fileinfo is required by composer.json but not loaded', $result->findings);
        self::assertContains('PHP extension xml is required by composer.json but not loaded', $result->findings);
    }

    public function testASettingPhpDoesNotKnowIsReportedAsUnset(): void
    {
        $result = $this->checked($this->runtime(), self::MANIFEST, "precision=14\n");

        self::assertSame('PHP setting precision is unset but config/gates/php/check.ini says 14', $result->findings[0]);
    }

    public function testAKeyOutsideTheAllowlistFailsAndNamesIt(): void
    {
        $result = $this->checked($this->runtime(), self::MANIFEST, self::CHECK_INI."auto_prepend_file=x.php\n");

        self::assertSame(['PHP setting auto_prepend_file in config/gates/php/check.ini is not an allowed setting'], $result->findings);
    }

    public function testAVariableInTheCheckIniIsReportedAsWritten(): void
    {
        $result = $this->checked($this->runtime(), self::MANIFEST, "memory_limit=\${LIMIT}\n");

        self::assertStringContainsString('says ${LIMIT}', $result->findings[0]);
    }

    public function testASettingDifferenceSaysHowToLoadTheProfile(): void
    {
        $result = $this->checked($this->runtime(settings: ['zend.assertions' => '-1']));

        self::assertStringContainsString('PHP_INI_SCAN_DIR', $result->findings[1]);
        self::assertStringContainsString('config/gates/php', $result->findings[1]);
    }

    public function testSeveralDifferencesAreAllNamed(): void
    {
        $result = $this->checked($this->runtime('8.4.3', ['ctype'], ['memory_limit' => '128M']));

        self::assertCount(4, $result->findings);
    }

    public function testAMissingCheckIniFails(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('composer.json', self::MANIFEST);

        $result = new EnvironmentGate($this->runtime())->run(new Workspace($sandbox->root, $sandbox->root, Mode::Full, null));

        self::assertSame(['config/gates/php/check.ini is missing or not valid'], $result->findings);
    }

    public function testAMissingManifestFails(): void
    {
        $sandbox = $this->emptySandbox();

        $result = new EnvironmentGate($this->runtime())->run(new Workspace($sandbox->root, $sandbox->root, Mode::Full, null));

        self::assertSame(['composer.json is missing or not valid JSON'], $result->findings);
    }

    /**
     * @param list<string>          $extensions
     * @param array<string, string> $settings
     */
    private function runtime(string $version = '8.5.12', array $extensions = ['ctype', 'intl'], array $settings = []): PhpRuntime
    {
        $settings += ['zend.assertions' => '1', 'error_reporting' => '30719', 'memory_limit' => '-1'];

        return new PhpRuntime($version, $extensions, static fn (string $name): ?string => $settings[$name] ?? null);
    }

    private function checked(PhpRuntime $runtime, string $manifest = self::MANIFEST, string $checkIni = self::CHECK_INI): GateResult
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('composer.json', $manifest);
        $sandbox->write('config/gates/php/check.ini', $checkIni);

        return new EnvironmentGate($runtime)->run(new Workspace($sandbox->root, $sandbox->root, Mode::Full, null));
    }
}
