<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\ProcessRunner;
use Intakt\Gates\WorkflowsGate;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(WorkflowsGate::class)]
final class WorkflowsGateTest extends SandboxTestCase
{
    public function testAPullRequestTitleInARunStepFailsTheGate(): void
    {
        $run = $this->withStep('run: echo "${{ github.event.pull_request.title }}"')->check('--only=workflows');

        self::assertStringContainsString('.github/workflows/t.yml:8 template-injection', $run->findingsOf('workflows'));
    }

    public function testAnActionPinnedToATagFailsTheGate(): void
    {
        $run = $this->withStep('uses: actions/checkout@v4')->check('--only=workflows');

        self::assertStringContainsString('.github/workflows/t.yml:8 unpinned-uses', $run->findingsOf('workflows'));
    }

    public function testOurGithubFolderPassesWithATokenSet(): void
    {
        $home = dirname(__DIR__, 2);
        $run = new CheckRun(new ProcessRunner()->run(['php', $home.'/bin/check', '--root='.$home, '--only=workflows'], $home, ['GH_TOKEN' => 'unused']));

        self::assertTrue($run->passed('workflows'), $run->output());
    }

    public function testAMissingZizmorFailsTheGate(): void
    {
        $tools = $this->emptySandbox();

        foreach (['php', 'git', 'sh', 'setsid'] as $name) {
            $tools->symlink($name, trim((string) shell_exec('command -v '.$name)));
        }

        $run = $this->withStep('run: true')->checkWith(['PATH' => $tools->root], '--only=workflows');

        self::assertSame('missing tool zizmor', $run->findingsOf('workflows'), $run->output());
    }

    private function withStep(string $step): Sandbox
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('.github/workflows/t.yml', "name: t\non: pull_request\npermissions: {}\njobs:\n  t:\n    runs-on: ubuntu-latest\n    steps:\n      - {$step}\n");

        return $sandbox;
    }
}
