<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

/**
 * @phpstan-type Step array{name?: string, uses?: string, run?: string}
 * @phpstan-type Job array{permissions?: array<string, string>, if?: string, environment?: array{name?: string}, steps?: list<Step>}
 * @phpstan-type WorkflowFile array{on: array<string, mixed>, permissions: array<string, string>, jobs: array<string, Job>}
 */
#[CoversNothing]
final class HighlightsWorkflowTest extends TestCase
{
    private const string WORKFLOW = __DIR__.'/../../.github/workflows/highlights.yml';
    private const string MAIN_ONLY = "github.repository == 'shinyredapples/intakt' && github.ref == 'refs/heads/main'";

    public function testTheWorkflowRunsOnlyOnPushesToMainAndOnDemand(): void
    {
        $workflow = $this->workflow();

        self::assertSame(['push', 'workflow_dispatch'], array_keys($workflow['on']));
        self::assertSame(['branches' => ['main']], $workflow['on']['push']);
    }

    public function testTheWorkflowTokenIsReadOnlyByDefault(): void
    {
        self::assertSame(['contents' => 'read'], $this->workflow()['permissions']);
    }

    public function testOnlyThePublishJobMayDeployPages(): void
    {
        $jobs = $this->workflow()['jobs'];

        // Because jekyll-build-pages reads the Pages settings, the build job may read them; only the publish job may write.
        self::assertSame(['contents' => 'read', 'pages' => 'read'], $jobs['build']['permissions'] ?? null);
        self::assertSame(['pages' => 'write', 'id-token' => 'write'], $jobs['publish']['permissions'] ?? null);
    }

    public function testBothJobsRunOnlyForMainOfThisRepository(): void
    {
        $jobs = $this->workflow()['jobs'];

        self::assertSame(['build' => self::MAIN_ONLY, 'publish' => self::MAIN_ONLY], array_map(static fn (array $job): ?string => $job['if'] ?? null, $jobs));
    }

    public function testThePublishJobRunsNoRepositoryCode(): void
    {
        $steps = $this->workflow()['jobs']['publish']['steps'] ?? [];

        self::assertSame([], array_filter($steps, static fn (array $step): bool => isset($step['run']) || str_starts_with($step['uses'] ?? '', 'actions/checkout@') || !str_contains($step['uses'] ?? '', '@')));
    }

    public function testThePublishJobOnlyDeploysPagesToTheGithubPagesEnvironment(): void
    {
        $publish = $this->workflow()['jobs']['publish'];

        self::assertSame('github-pages', $publish['environment']['name'] ?? null);
        self::assertSame(['actions/deploy-pages'], array_map(static fn (array $step): string => explode('@', $step['uses'] ?? '')[0], $publish['steps'] ?? []));
    }

    public function testTheAssembledPageIsTheHighlightsBehindAFrontMatterThatTurnsOffLiquid(): void
    {
        $page = $this->assembledPage("# Highlights\n\nA code span `{{ x }}` and `{% if y %}`.\n");

        self::assertSame("---\nrender_with_liquid: false\n---\n# Highlights\n\nA code span `{{ x }}` and `{% if y %}`.\n", $page);
    }

    public function testTheAssembledPageRunsBinHighlightsBeforeItWritesThePage(): void
    {
        self::assertStringStartsWith('bin/highlights && ', $this->pageCommand());
    }

    /**
     * Runs the step's write command on a stand-in `docs/highlights.md`, with the container's `/page` mapped to a temp folder.
     */
    private function assembledPage(string $highlights): string
    {
        $workspace = sys_get_temp_dir().'/intakt-highlights-'.bin2hex(random_bytes(6));
        mkdir($workspace.'/docs', 0o777, true);
        mkdir($workspace.'/page');
        file_put_contents($workspace.'/docs/highlights.md', $highlights);

        try {
            $write = substr($this->pageCommand(), strlen('bin/highlights && '));
            new Process(['sh', '-c', str_replace(' /page/', ' '.$workspace.'/page/', $write)], $workspace)->mustRun();

            return (string) file_get_contents($workspace.'/page/index.md');
        } finally {
            new Filesystem()->remove($workspace);
        }
    }

    private function pageCommand(): string
    {
        $steps = array_filter($this->workflow()['jobs']['build']['steps'] ?? [], static fn (array $step): bool => 'Assemble the page' === ($step['name'] ?? null));
        self::assertCount(1, $steps);
        self::assertSame(1, preg_match("/ sh -c '([^']*)'\\n\$/", array_first($steps)['run'] ?? '', $command));

        return $command[1];
    }

    /**
     * @return WorkflowFile
     */
    private function workflow(): array
    {
        self::assertFileExists(self::WORKFLOW);

        /** @var WorkflowFile $workflow */
        $workflow = Yaml::parseFile(self::WORKFLOW);

        return $workflow;
    }
}
