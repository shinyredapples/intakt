<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\RectorGate;
use Intakt\Gates\RectorPathsFile;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Filesystem\Filesystem;

#[CoversClass(RectorGate::class)]
#[CoversClass(RectorPathsFile::class)]
final class RectorGateTest extends SandboxTestCase
{
    public function testAClassNameWrittenAsAStringFailsTheRectorDryRun(): void
    {
        $this->assertRejectedBy('rector', 'class-name-as-string', 'StringClassNameToClassConstantRector');
    }

    public function testDeadCodeFailsTheRectorDryRun(): void
    {
        $this->assertRejectedBy('rector', 'dead-code', 'RemoveUnusedVariableAssignRector');
    }

    // Because Rector passes its command-line paths to every parallel worker in one shell string, which Linux caps at 128 KiB.
    public function testAFileListLongerThanOneShellArgumentStillReachesEveryWorker(): void
    {
        $sandbox = $this->emptySandbox();
        $folder = 'src/'.str_repeat('a_folder_with_a_long_name/', 5);

        for ($index = 0; $index < 1_000; ++$index) {
            $sandbox->write($folder.'Clean'.$index.'.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace Intakt\\Sample;\n\nfinal class Clean{$index}\n{\n}\n");
        }

        $sandbox->write($folder.'Report.php', (string) file_get_contents(__DIR__.'/Fixtures/dead-code/src/Report.php.fixture'));

        $run = $sandbox->check('--only=rector');

        self::assertGreaterThan(128 * 1024, 1_001 * strlen($folder.'Clean1000.php '));
        self::assertTrue($run->failed('rector'), $run->output());
        self::assertStringContainsString('RemoveUnusedVariableAssignRector', $run->findingsOf('rector'));
    }

    // Because a run that died while writing could leave a partial list under the name the next run with the same files looks for.
    public function testAPartlyWrittenPathsFileUnderItsFinalNameNeverLetsTheGatePassWithFewerFiles(): void
    {
        $sandbox = $this->sandboxWith('dead-code');
        $folder = sys_get_temp_dir().'/rector-partial-paths-'.getmypid();
        $cache = ['INTAKT_GATES_CACHE' => $folder];
        $sandbox->write('src/Clean.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace Intakt\\Sample;\n\nfinal class Clean\n{\n}\n");

        try {
            $sandbox->checkWith($cache, '--only=rector');
            $written = glob($folder.'/rector-paths/*.json');
            self::assertIsArray($written);
            self::assertCount(1, $written);
            file_put_contents($written[0], '["src/Clean.php"');

            $run = $sandbox->checkWith($cache, '--only=rector');
        } finally {
            new Filesystem()->remove($folder);
        }

        self::assertTrue($run->failed('rector'), $run->output());
        self::assertStringContainsString('RemoveUnusedVariableAssignRector', $run->findingsOf('rector'));
    }

    // Because Rector with no paths of its own checks nothing and reports success.
    public function testRectorFailsItsRunWhenThePathsFileHoldsNoPaths(): void
    {
        $sandbox = $this->sandboxWith('dead-code');
        $sandbox->write('paths.json', '[]');
        $home = dirname(__DIR__, 2);

        $result = $sandbox->runScript($home.'/vendor/bin/rector process --dry-run --no-progress-bar --config='.$home.'/config/gates/rector.php', ['INTAKT_RECTOR_PATHS_FILE' => $sandbox->root.'/paths.json', 'INTAKT_GATES_CACHE' => $sandbox->root.'/gates-cache']);

        self::assertNotSame(0, $result->exitCode, $result->output);
        self::assertStringContainsString('holds no list of paths', $result->output.$result->errors);
    }

    // Because a list with one path per line would split this name in two and check neither half.
    public function testAFileWhoseNameHoldsANewlineStillGetsItsDeadCodeFinding(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write("src/Line\nBreak.php", (string) file_get_contents(__DIR__.'/Fixtures/dead-code/src/Report.php.fixture'));

        $run = $sandbox->check('--only=rector');

        self::assertTrue($run->failed('rector'), $run->output());
        self::assertStringContainsString('RemoveUnusedVariableAssignRector', $run->findingsOf('rector'));
    }

    // Because Rector runs in the checked root while bin/check resolves a relative gates cache against its own directory.
    public function testARelativeGatesCacheWithAnotherRootStillChecksEveryFile(): void
    {
        $sandbox = $this->sandboxWith('dead-code');
        $relative = 'var/rector-relative-cache-'.getmypid();
        $home = dirname(__DIR__, 2);

        try {
            $run = $sandbox->checkWith(['INTAKT_GATES_CACHE' => $relative], '--only=rector');
        } finally {
            new Filesystem()->remove($home.'/'.$relative);
        }

        self::assertTrue($run->failed('rector'), $run->output());
        self::assertStringContainsString('RemoveUnusedVariableAssignRector', $run->findingsOf('rector'));
    }

    // Because a paths file that cannot be written must fail the gate with its reason, not end the gate's process.
    public function testAPathsFileThatCannotBeWrittenFailsTheGateWithItsReason(): void
    {
        $sandbox = $this->sandboxWith('dead-code');
        $folder = sys_get_temp_dir().'/rector-unwritable-paths-'.getmypid();
        new Filesystem()->mkdir($folder);
        file_put_contents($folder.'/rector-paths', 'a file where the folder belongs');

        try {
            $run = $sandbox->checkWith(['INTAKT_GATES_CACHE' => $folder], '--only=rector');
        } finally {
            new Filesystem()->remove($folder);
        }

        self::assertTrue($run->failed('rector'), $run->output());
        self::assertStringContainsString('cannot write the rector paths file', $run->findingsOf('rector'));
    }
}
