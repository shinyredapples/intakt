<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\PhpRuntime;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

#[CoversClass(PhpRuntime::class)]
final class PhpRuntimeTest extends TestCase
{
    public function testAPhpWithoutAnIniFileReportsTheErrorLevelItApplies(): void
    {
        self::assertSame((string) E_ALL, $this->settingWithoutIni('error_reporting'));
    }

    public function testAPhpWithoutAnIniFileReportsAnOrdinarySettingAsIs(): void
    {
        self::assertSame('1', $this->settingWithoutIni('zend.assertions'));
    }

    private function settingWithoutIni(string $name): string
    {
        $process = new Process([
            PHP_BINARY,
            '-n',
            '-r',
            'require $argv[1]; echo var_export(Intakt\Gates\PhpRuntime::current()->setting($argv[2]), true);',
            dirname(__DIR__, 2).'/vendor/autoload.php',
            $name,
        ]);
        $process->mustRun();

        return trim($process->getOutput(), "'");
    }
}
