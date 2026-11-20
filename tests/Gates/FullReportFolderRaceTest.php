<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\FullReport;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Process\Process;

#[CoversClass(FullReport::class)]
final class FullReportFolderRaceTest extends SandboxTestCase
{
    // A child process replaces mkdir() in the gates' namespace so that a competing writer creates var/check immediately before the first writer calls the real mkdir(), forcing the lost race.
    private const string RACE = <<<'PHP'
        namespace Intakt\Gates {
            function mkdir(string $directory, int $permissions = 0777, bool $recursive = false): bool
            {
                if ($directory === ($GLOBALS['race_folder'] ?? null)) {
                    unset($GLOBALS['race_folder']);
                    $GLOBALS['race_first'] = $GLOBALS['race_writer']->write();
                }

                return \mkdir($directory, $permissions, $recursive);
            }
        }

        namespace {
            require $argv[1];
            $result = Intakt\Gates\GateResult::passed('dashes');
            $writer = new Intakt\Gates\FullReport($argv[2], [Intakt\Gates\GateReport::fromChild('dashes', new Intakt\Gates\ProcessResult(0, $result->toJson()."\n", ''))]);
            $GLOBALS['race_writer'] = $writer;
            $GLOBALS['race_folder'] = $argv[2].'/'.Intakt\Gates\FullReport::FOLDER;
            $second = $writer->write();
            echo json_encode([$GLOBALS['race_first'] ?? null, $second]);
        }
        PHP;

    public function testAWriterThatLosesTheRaceToCreateTheReportFolderStillWritesItsReport(): void
    {
        $sandbox = $this->emptySandbox();

        $process = new Process([PHP_BINARY, '-r', self::RACE, dirname(__DIR__, 2).'/vendor/autoload.php', $sandbox->root]);
        $process->mustRun();

        $files = array_filter((array) json_decode($process->getOutput(), true), is_string(...));
        self::assertCount(2, array_unique($files), $process->getOutput());
        foreach ($files as $file) {
            self::assertFileExists($sandbox->root.'/'.$file);
        }
    }
}
