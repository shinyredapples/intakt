<?php

declare(strict_types=1);

namespace Intakt\Tests\Journeys\Support;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

#[CoversClass(InstallProcessRunner::class)]
final class InstallProcessRunnerTest extends TestCase
{
    public function testAStepThatPassesItsLimitIsAFailedStepWithTheReason(): void
    {
        [$code, $output] = new InstallProcessRunner(1)('echo started; sleep 5', (string) getcwd(), []);

        self::assertSame(InstallProcessRunner::TIMED_OUT, $code);
        self::assertStringContainsString('started', $output);
        self::assertStringContainsString('stopped after 1 seconds', $output);
    }

    public function testATimedOutStepLeavesNoChildRunning(): void
    {
        $folder = sys_get_temp_dir().'/intakt-runner-test-'.bin2hex(random_bytes(6));
        $files = new Filesystem();
        $files->dumpFile($folder.'/child.sh', "while [ ! -f release ]; do sleep 0.1; done\necho late > out\n");

        try {
            [$code] = new InstallProcessRunner(1)('sh child.sh && true', $folder, []);
            $files->touch($folder.'/release');
            usleep(1_000_000);

            self::assertSame(InstallProcessRunner::TIMED_OUT, $code);
            self::assertFileDoesNotExist($folder.'/out');
        } finally {
            $files->remove($folder);
        }
    }

    public function testAStepSeesNeitherAProviderKeyNorAForgeTokenOfTheParent(): void
    {
        putenv('OPENAI_API_KEY=stand-in-key');
        putenv('GH_TOKEN=stand-in-token');

        try {
            [$code, $output] = new InstallProcessRunner(10)('echo "[$OPENAI_API_KEY][$GH_TOKEN][$STEP_ADDS]"; command -v sh', (string) getcwd(), ['STEP_ADDS' => 'added']);
        } finally {
            putenv('OPENAI_API_KEY');
            putenv('GH_TOKEN');
        }

        self::assertSame(0, $code);
        self::assertStringStartsWith('[][][added]', $output);
    }

    public function testTheExitCodeAndBothOutputsOfAStepAreAnswered(): void
    {
        self::assertSame([3, "out\nerr\n"], new InstallProcessRunner(10)('echo out; echo err >&2; exit 3', (string) getcwd(), []));
    }
}
