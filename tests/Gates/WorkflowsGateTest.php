<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\WorkflowsGate;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(WorkflowsGate::class)]
final class WorkflowsGateTest extends SandboxTestCase
{
    private const string PINNED_CHECKOUT = 'actions/checkout@11bd71901bbe5b1630ceea73d27597364c9af683';

    public function testAPullRequestTitleInARunStepFailsTheGate(): void
    {
        $sandbox = $this->sandboxWithWorkflow(self::PINNED_CHECKOUT, 'echo "${{ github.event.pull_request.title }}"');

        $run = $sandbox->check('--only=workflows');

        self::assertSame(1, $run->exitCode(), $run->output());
        self::assertStringContainsString('.github/workflows/t.yml:11 template-injection', $run->findingsOf('workflows'));
    }

    public function testAnActionPinnedToATagFailsTheGate(): void
    {
        $run = $this->sandboxWithWorkflow('actions/checkout@v4', 'true')->check('--only=workflows');

        self::assertSame(1, $run->exitCode(), $run->output());
        self::assertStringContainsString('.github/workflows/t.yml:8 unpinned-uses', $run->findingsOf('workflows'));
    }

    public function testAZizmorConfigurationInTheTreeCannotDisableAnAudit(): void
    {
        $sandbox = $this->sandboxWithWorkflow('actions/checkout@v4', 'true');
        $sandbox->write('.github/zizmor.yml', "rules:\n  unpinned-uses:\n    disable: true\n");

        $run = $sandbox->check('--only=workflows');

        self::assertTrue($run->failed('workflows'), $run->output());
    }

    public function testTheRepositorysOwnWorkflowsPassTheGateWithATokenInTheEnvironment(): void
    {
        $sandbox = $this->emptySandbox();
        $home = dirname(__DIR__, 2);

        foreach (['.github/dependabot.yml', ...(glob($home.'/.github/workflows/*.yml') ?: [])] as $file) {
            $relative = str_replace($home.'/', '', $file);
            $sandbox->write($relative, (string) file_get_contents($home.'/'.$relative));
        }

        $run = $sandbox->checkWith(['GH_TOKEN' => 'unused', 'GITHUB_TOKEN' => 'unused', 'ZIZMOR_GITHUB_TOKEN' => 'unused'], '--only=workflows');

        self::assertSame(0, $run->exitCode(), $run->output());
    }

    public function testATreeWithoutAGithubFolderPassesTheGate(): void
    {
        $run = $this->emptySandbox()->check('--only=workflows');

        self::assertTrue($run->passed('workflows'), $run->output());
    }

    public function testAMissingZizmorIsListedBeforeAnyGateRuns(): void
    {
        $tools = $this->emptySandbox();

        foreach (['php' => PHP_BINARY, 'git' => $this->onPath('git'), 'sh' => $this->onPath('sh'), 'setsid' => $this->onPath('setsid')] as $name => $target) {
            $tools->symlink($name, $target);
        }

        $run = $this->sandboxWithWorkflow(self::PINNED_CHECKOUT, 'true')->checkWith(['PATH' => $tools->root], '--only=workflows');

        self::assertSame(2, $run->exitCode(), $run->output());
        self::assertStringContainsString('"zizmor not found, see docs/architecture/requirements.md"', $run->output());
    }

    private function sandboxWithWorkflow(string $action, string $script): Sandbox
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('.github/workflows/t.yml', <<<YAML
            name: t
            on: pull_request
            permissions: {}
            jobs:
              t:
                runs-on: ubuntu-latest
                steps:
                  - uses: {$action}
                    with:
                      persist-credentials: false
                  - run: |
                      {$script}

            YAML);

        return $sandbox;
    }

    private function onPath(string $command): string
    {
        return trim((string) shell_exec('command -v '.$command));
    }
}
