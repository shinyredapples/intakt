<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\FullReport;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Process\Process;

#[CoversClass(FullReport::class)]
final class FullReportShortWriteTest extends SandboxTestCase
{
    // A child process caps the size of the files it writes (RLIMIT_FSIZE, with SIGXFSZ ignored so the write fails instead of killing it), forcing a short write of the report under display_errors=1.
    private const string SHORT_WRITE = <<<'PHP'
        require $argv[1];
        ini_set('display_errors', '1');
        error_reporting(-1);
        pcntl_signal(SIGXFSZ, SIG_IGN);
        $result = Intakt\Gates\GateResult::failedBecause('dashes', str_repeat('notes.md:1 ', 20));
        $writer = new Intakt\Gates\FullReport($argv[2], [Intakt\Gates\GateReport::fromChild('dashes', new Intakt\Gates\ProcessResult(1, $result->toJson()."\n", ''))]);
        mkdir($argv[2].'/'.Intakt\Gates\FullReport::FOLDER, 0o777, true);
        posix_setrlimit(POSIX_RLIMIT_FSIZE, 16, 16);
        echo json_encode(['report' => $writer->write()]);
        PHP;

    public function testAShortWriteLeavesNoReportFileAndPrintsNothingButItsCaller(): void
    {
        $sandbox = $this->emptySandbox();

        $process = new Process([PHP_BINARY, '-r', self::SHORT_WRITE, dirname(__DIR__, 2).'/vendor/autoload.php', $sandbox->root]);
        $process->mustRun();

        self::assertSame('{"report":null}', $process->getOutput());
        self::assertSame([], array_values(array_diff((array) scandir($sandbox->root.'/'.FullReport::FOLDER), ['.', '..'])));
    }
}
