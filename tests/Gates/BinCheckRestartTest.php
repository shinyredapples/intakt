<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\EnvironmentGate;
use Intakt\Gates\GateResult;
use Intakt\Gates\PhpStartupOptions;
use Intakt\Gates\ProcessResult;
use Intakt\Gates\ProcessRunner;
use Intakt\Gates\ProfileScanPath;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(PhpStartupOptions::class)]
#[CoversClass(GateResult::class)]
#[UsesClass(EnvironmentGate::class)]
#[UsesClass(ProfileScanPath::class)]
#[UsesClass(ProcessRunner::class)]
#[UsesClass(ProcessResult::class)]
final class BinCheckRestartTest extends SandboxTestCase
{
    public function testAPhpWhoseIniDisablesAssertionsComesBackWithTheCheckSettings(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('assertions-off.ini', "zend.assertions=-1\n");

        $result = $this->environmentGate([], ':'.$sandbox->root);

        self::assertStringNotContainsString('zend.assertions', $result->output);
    }

    public function testASettingGivenWithDashDStillWinsAfterTheRestartAndFailsTheGate(): void
    {
        $result = $this->environmentGate(['-d', 'zend.assertions=-1'], ':');

        self::assertSame([1, true], [$result->exitCode, str_contains($result->output, 'PHP setting zend.assertions is -1 but config/php/check.ini says 1')]);
    }

    public function testAPhpWithoutExtensionsGetsTheEnvironmentFindingsInsteadOfACrash(): void
    {
        $result = $this->environmentGate(['-n'], dirname(__DIR__, 2).'/config/php');

        self::assertSame([1, true], [$result->exitCode, str_contains($result->output, '"gate":"environment","ok":false')]);
    }

    /**
     * @param list<string> $options
     */
    private function environmentGate(array $options, string $scanPath): ProcessResult
    {
        $root = dirname(__DIR__, 2);

        return new ProcessRunner()->run([PHP_BINARY, ...$options, $root.'/bin/check', '--only=environment'], $root, ['PHP_INI_SCAN_DIR' => $scanPath]);
    }
}
