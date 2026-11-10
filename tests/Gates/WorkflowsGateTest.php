<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\WorkflowsGate;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(WorkflowsGate::class)]
final class WorkflowsGateTest extends SandboxTestCase
{
    public function testAPullRequestTitleInARunStepFailsTheGate(): void
    {
        self::assertStringContainsString('t.yml:8 template-injection', $this->findingsOf('run: echo "${{ github.event.pull_request.title }}"'));
    }

    public function testAnActionPinnedToATagFailsTheGate(): void
    {
        self::assertStringContainsString('t.yml:8 unpinned-uses', $this->findingsOf('uses: actions/checkout@v4'));
    }

    public function testABrokenWorkflowNextToAValidOneFailsWithZizmorsParseError(): void
    {
        $sandbox = $this->sandboxWithStep('run: true');
        $sandbox->write('.github/workflows/u.yml', self::workflow('run: ['));

        self::assertStringStartsWith(
            'zizmor failed: fatal: no audit was performed failed to load file://.github/workflows/u.yml as workflow Caused by: 0: invalid YAML syntax',
            $sandbox->checkWith([], '--only=workflows')->findingsOf('workflows'),
        );
    }

    public function testAMissingZizmorFailsTheGate(): void
    {
        $tools = $this->emptySandbox();

        foreach (['php', 'git', 'sh', 'setsid'] as $name) {
            $tools->symlink($name, trim((string) shell_exec('command -v '.$name)));
        }

        self::assertSame('missing tool zizmor', $this->findingsOf('run: true', ['PATH' => $tools->root]));
    }

    /** @param array<string, string> $environment */
    private function findingsOf(string $step, array $environment = []): string
    {
        return $this->sandboxWithStep($step)->checkWith($environment, '--only=workflows')->findingsOf('workflows');
    }

    private function sandboxWithStep(string $step): Sandbox
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('.github/workflows/t.yml', self::workflow($step));

        return $sandbox;
    }

    private static function workflow(string $step): string
    {
        return "name: t\non: pull_request\npermissions: {}\njobs:\n  t:\n    runs-on: ubuntu-latest\n    steps:\n      - {$step}\n";
    }
}
