<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\WorkflowsGate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversClass(WorkflowsGate::class)]
final class WorkflowsGateTest extends SandboxTestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function flaggedSteps(): iterable
    {
        yield 'an event field in a run step' => ['run: echo "${{ github.event.pull_request.title }}"', 'template-injection: code injection'];
        yield 'an action pinned to a tag' => ['uses: docker/login-action@v3', 'unpinned-uses'];
        yield 'an ignore without a reason' => ['uses: docker/login-action@v3 # zizmor: ignore[unpinned-uses] .', 'zizmor ignore without a reason'];
    }

    #[DataProvider('flaggedSteps')]
    public function testAFlaggedStepFailsNamingItsLine(string $step, string $finding): void
    {
        $run = $this->workflow($step)->check('--only=workflows');

        self::assertStringContainsString('.github/workflows/ci.yml:7: '.$finding, $run->findingsOf('workflows'));
    }

    public function testAnIgnoreFollowedByItsReasonPasses(): void
    {
        $run = $this->workflow('uses: docker/login-action@v3 # zizmor: ignore[unpinned-uses] immutable tags')->check('--only=workflows');

        self::assertTrue($run->passed('workflows'), $run->output());
    }

    public function testAnUnreadableWorkflowFails(): void
    {
        $this->skipWhenPermissionsAreIgnored();
        $sandbox = $this->workflow('run: "true"');
        chmod($sandbox->root.'/.github/workflows/ci.yml', 0o000);

        self::assertSame('.github/workflows/ci.yml cannot be read', $sandbox->check('--only=workflows')->findingsOf('workflows'));
    }

    public function testAWorkflowZizmorCannotParseFailsWithItsReason(): void
    {
        $sandbox = $this->workflow('run: "true"');
        $sandbox->write('.github/workflows/ci.yml', "jobs: [unclosed\n");

        self::assertStringStartsWith('zizmor failed: did not find expected', $sandbox->check('--only=workflows')->findingsOf('workflows'));
    }

    public function testAConfigurationFileThatDisablesAnAuditIsNotRead(): void
    {
        $sandbox = $this->workflow('uses: docker/login-action@v3');
        $sandbox->write('.github/zizmor.yml', "rules:\n  unpinned-uses:\n    disable: true\n");

        self::assertTrue($sandbox->check('--only=workflows')->failed('workflows'));
    }

    public function testTheRepositorysOwnWorkflowsPass(): void
    {
        $sandbox = $this->workflow('run: "true"');

        foreach (['dependabot.yml', 'workflows/ci.yml', 'workflows/first-start.yml', 'workflows/release-labels.yml'] as $file) {
            $sandbox->write('.github/'.$file, (string) file_get_contents(dirname(__DIR__, 2).'/.github/'.$file));
        }

        self::assertTrue($sandbox->check('--only=workflows')->passed('workflows'));
    }

    public function testAnotherZizmorVersionFails(): void
    {
        $sandbox = $this->workflow('run: "true"');
        $sandbox->write('bin/zizmor', "#!/bin/sh\necho zizmor 1.0.0\n");
        chmod($sandbox->root.'/bin/zizmor', 0o755);

        $run = $sandbox->checkWith(['PATH' => $sandbox->root.'/bin:'.getenv('PATH')], '--only=workflows');

        self::assertStringContainsString('zizmor 1.32.0 is required', $run->findingsOf('workflows'));
    }

    private function workflow(string $step): Sandbox
    {
        $sandbox = $this->emptySandbox();
        $sandbox->startRepository();
        $sandbox->write('.github/workflows/ci.yml', "on: pull_request\npermissions: {}\njobs:\n  greet:\n    runs-on: ubuntu-latest\n    steps:\n      - ".$step."\n");

        return $sandbox;
    }
}
