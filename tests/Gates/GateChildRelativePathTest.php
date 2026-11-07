<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\CheckCommand;
use Intakt\Gates\GateChildren;
use Intakt\Gates\ProcessGroup;
use Intakt\Gates\ProcessResult;
use Intakt\Gates\ProcessRunner;
use Intakt\Gates\StopSignalForwarding;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Component\Process\ExecutableFinder;

#[CoversClass(GateChildren::class)]
#[CoversClass(ProcessRunner::class)]
#[UsesClass(CheckCommand::class)]
#[UsesClass(ProcessGroup::class)]
#[UsesClass(ProcessResult::class)]
#[UsesClass(StopSignalForwarding::class)]
final class GateChildRelativePathTest extends SandboxTestCase
{
    public function testAGateChildStartsWithToolsThatARelativePathEntryFindsInTheWorkspace(): void
    {
        $check = $this->checkWithToolsInTheWorkspace(['git', 'sh', 'setsid']);

        self::assertStringContainsString('{"gate":"dashes","ok":true', $check->output, $check->errors);
        self::assertSame(0, $check->exitCode);
    }

    public function testARunStopsBeforeAnyGateWhenARelativePathEntryLacksTheLauncher(): void
    {
        $check = $this->checkWithToolsInTheWorkspace(['git', 'sh']);

        self::assertStringNotContainsString('"gate":"dashes"', $check->output);
        self::assertStringContainsString('setsid not found', $check->output);
        self::assertSame(2, $check->exitCode);
    }

    /**
     * @param list<string> $tools
     */
    private function checkWithToolsInTheWorkspace(array $tools): ProcessResult
    {
        // Because the git on the search path can be a wrapper script that asks `id` for the user before it runs git.
        $tools = [...$tools, 'id'];

        $sandbox = $this->emptySandbox();
        $sandbox->startRepository();
        $sandbox->write('README.md', "plain words\n");
        $sandbox->commitAll('chore: start');
        mkdir($sandbox->root.'/tools');

        foreach ($tools as $tool) {
            symlink((string) new ExecutableFinder()->find($tool), $sandbox->root.'/tools/'.$tool);
        }

        return new ProcessRunner()->run(
            [PHP_BINARY, dirname(__DIR__, 2).'/bin/check', '--only=dashes', '--root='.$sandbox->root],
            sys_get_temp_dir(),
            ['PATH' => 'tools'],
        );
    }
}
