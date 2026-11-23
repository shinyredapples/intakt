<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

/**
 * @phpstan-type Step array{name?: string, uses?: string, run?: string, env?: array<string, string>}
 * @phpstan-type Job array{permissions?: array<string, string>, if?: string, environment?: array{name?: string}, env?: array<string, string>, steps?: list<Step>}
 * @phpstan-type WorkflowFile array{on: array<string, mixed>, permissions: array<string, string>, env?: array<string, string>, jobs: array<string, Job>}
 */
#[CoversNothing]
final class HighlightsWorkflowTest extends TestCase
{
    private const string WORKFLOW = __DIR__.'/../../.github/workflows/highlights.yml';
    private const string PAGES = 'bin/pages /page --recorded=/workspace/_journey-api';
    private const string RECORD = 'bin/journey-record _journey-api';
    private const string ASSEMBLE = 'Assemble the page';
    private const string MAIN_ONLY = "github.repository == 'shinyredapples/intakt' && github.ref == 'refs/heads/main'";

    public function testTheWorkflowRunsOnInputsDailyAndByHand(): void
    {
        $workflow = $this->workflow();

        self::assertSame(['push', 'schedule', 'workflow_dispatch'], array_keys($workflow['on']));
        self::assertSame(['branches' => ['main'], 'paths' => ['docs/highlights.d/**', 'docs/pages/**', 'bin/highlights', 'config/gates/src/Highlight*.php', '.github/workflows/highlights.yml']], $workflow['on']['push']);
        self::assertSame([['cron' => '17 4 * * *']], $workflow['on']['schedule']);
    }

    public function testTheWorkflowTokenIsReadOnlyByDefault(): void
    {
        self::assertSame(['contents' => 'read'], $this->workflow()['permissions']);
    }

    public function testOnlyThePublishJobMayDeployPages(): void
    {
        $jobs = $this->workflow()['jobs'];

        // Because jekyll-build-pages and bin/journey-record read Pages, issues and pull requests, the build job may read them; only publish may write.
        self::assertSame(['contents' => 'read', 'pages' => 'read', 'issues' => 'read', 'pull-requests' => 'read'], $jobs['build']['permissions'] ?? null);
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

    public function testTheAssembledPageTurnsEveryLiquidOpeningOfTheHighlightsIntoALiquidStringThatPrintsIt(): void
    {
        $escaped = "# Highlights\n\nA code span `{{ \"{{\" }} x }}`, `{{ \"{%\" }} if y %}` and `{{ \"{{\" }}{{ \"{%\" }}`.\n";

        self::assertSame(['highlights/index.md' => $escaped, 'journey/index.md' => $escaped], $this->assembledPages("# Highlights\n\nA code span `{{ x }}`, `{% if y %}` and `{{{%`.\n"));
    }

    public function testTheAssembledPageIsTheHighlightsUnchangedWhenTheyHoldNoLiquidOpening(): void
    {
        $highlights = "# Highlights\n\nBraces `{ x }`, closings `}}` and `%}`, a percent `%{`.";

        self::assertSame(['highlights/index.md' => $highlights, 'journey/index.md' => $highlights], $this->assembledPages($highlights));
    }

    public function testTheAssembledPageRunsBinPagesOnTheRecordedAnswersBeforeItEscapesThePages(): void
    {
        self::assertStringStartsWith(self::PAGES.' && ', $this->pageCommand());
    }

    public function testTheBuildJobRecordsTheJourneyAnswersOnTheRunnerBeforeItAssemblesThePage(): void
    {
        $steps = $this->workflow()['jobs']['build']['steps'] ?? [];
        $record = array_keys(array_filter($steps, static fn (array $step): bool => self::RECORD === trim($step['run'] ?? '')));
        $assemble = array_keys(array_filter($steps, static fn (array $step): bool => self::ASSEMBLE === ($step['name'] ?? null)));

        self::assertCount(1, $record, 'The build job needs exactly one step that runs '.self::RECORD.'.');
        self::assertCount(1, $assemble);
        self::assertLessThan($assemble[0], $record[0]);
    }

    public function testOnlyTheRecordStepGetsTheJobTokenAndNoStepReadsASecret(): void
    {
        $workflow = $this->workflow();
        $build = $workflow['jobs']['build'];
        $record = array_first(array_filter($build['steps'] ?? [], static fn (array $step): bool => self::RECORD === trim($step['run'] ?? '')));
        self::assertNotNull($record);

        self::assertSame(['GH_TOKEN' => '${{ github.token }}'], $record['env'] ?? null);
        self::assertArrayNotHasKey('GH_TOKEN', $workflow['env'] ?? []);
        self::assertArrayNotHasKey('GH_TOKEN', $build['env'] ?? []);
        self::assertStringNotContainsString('secrets.', Yaml::dump($build['steps'] ?? [], 8));
    }

    public function testTheAssembledPageReadsTheRecordedAnswersAsTheContainerSeesThem(): void
    {
        self::assertStringContainsString(' --recorded=/workspace/_journey-api ', $this->pageCommand());
    }

    public function testThePageStepFailsWhenTheEscapeFails(): void
    {
        self::assertNotSame(0, $this->runPageWrite("# Highlights\n", 'sed() { return 1; }; ')['status']);
    }

    /**
     * @return array<string, string>
     */
    private function assembledPages(string $highlights): array
    {
        $run = $this->runPageWrite($highlights, '');
        self::assertSame(0, $run['status']);

        return $run['pages'];
    }

    /**
     * @return array{status: ?int, pages: array<string, string>}
     */
    private function runPageWrite(string $highlights, string $prelude): array
    {
        $workspace = sys_get_temp_dir().'/intakt highlights '.bin2hex(random_bytes(6));
        $filesystem = new Filesystem();
        $filesystem->dumpFile($workspace.'/page/highlights/index.md', $highlights);
        $filesystem->dumpFile($workspace.'/page/journey/index.md', $highlights);

        try {
            $write = substr($this->pageCommand(), strlen(self::PAGES.' && '));
            $process = new Process(['sh', '-c', $prelude.str_replace([' /page/highlights/index.md', ' /page/journey/index.md'], [' '.escapeshellarg($workspace.'/page/highlights/index.md'), ' '.escapeshellarg($workspace.'/page/journey/index.md')], $write)], $workspace);
            $process->run();

            $pages = [];
            foreach (['highlights/index.md', 'journey/index.md'] as $page) {
                $pages[$page] = is_file($workspace.'/page/'.$page) ? (string) file_get_contents($workspace.'/page/'.$page) : '';
            }

            return ['status' => $process->getExitCode(), 'pages' => $pages];
        } finally {
            $filesystem->remove($workspace);
        }
    }

    private function pageCommand(): string
    {
        $steps = array_filter($this->workflow()['jobs']['build']['steps'] ?? [], static fn (array $step): bool => self::ASSEMBLE === ($step['name'] ?? null));
        self::assertCount(1, $steps);
        if (1 !== preg_match("/ sh -c '([^']*)'\\n\$/", array_first($steps)['run'] ?? '', $command)) {
            self::fail('The step "Assemble the page" ends with no single-quoted sh -c command.');
        }

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
