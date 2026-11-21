<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\RectorGate;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(RectorGate::class)]
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
}
