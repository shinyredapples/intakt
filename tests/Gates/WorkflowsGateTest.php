<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\WorkflowsGate;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(WorkflowsGate::class)]
final class WorkflowsGateTest extends SandboxTestCase
{
    private const string REMOTE_FINDING = '[{"ident":"unpinned-uses","desc":"unpinned action reference","locations":[{"symbolic":{"key":{"Remote":{"slug":"example/repo","path":".github/workflows/t.yml"}}},"concrete":{"location":{"start_point":{"row":7,"column":8}}}}]}]';

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
        self::assertSame('missing tool zizmor', $this->findingsOf('run: true', ['PATH' => $this->toolsWithoutZizmor()->root]));
    }

    public function testACleanWorkflowPassesTheGate(): void
    {
        self::assertTrue($this->sandboxWithStep('run: true')->check('--only=workflows')->passed('workflows'));
    }

    public function testAFindingOutsideALocalFileFailsTheGateWithoutAPath(): void
    {
        $tools = $this->toolsWithoutZizmor();
        $tools->write('zizmor', "#!/bin/sh\necho '".self::REMOTE_FINDING."'\nexit 14\n");
        chmod($tools->root.'/zizmor', 0o755);

        self::assertSame('outside a local file unpinned-uses: unpinned action reference', $this->findingsOf('run: true', ['PATH' => $tools->root]));
    }

    public function testAFindingWithoutALocationFailsTheGateWithoutAPath(): void
    {
        $tools = $this->toolsWithoutZizmor();
        $tools->write('zizmor', "#!/bin/sh\necho '[{\"ident\":\"excessive-permissions\",\"desc\":\"overly broad permissions\",\"locations\":[]}]'\nexit 14\n");
        chmod($tools->root.'/zizmor', 0o755);

        self::assertSame('outside a local file excessive-permissions: overly broad permissions', $this->findingsOf('run: true', ['PATH' => $tools->root]));
    }

    private function toolsWithoutZizmor(): Sandbox
    {
        $tools = $this->emptySandbox();

        foreach (['php', 'git', 'sh', 'setsid'] as $name) {
            $tools->symlink($name, trim((string) shell_exec('command -v '.$name)));
        }

        return $tools;
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
