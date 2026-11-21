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
    private const string LOGIN = '.github/ci/docker-hub-login.sh';
    private const string ASSEMBLE = 'Assemble the page';
    private const string MAIN_ONLY = "github.repository == 'shinyredapples/intakt' && github.ref == 'refs/heads/main'";

    public function testTheWorkflowRunsOnInputsDailyAndByHand(): void
    {
        $workflow = $this->workflow();

        self::assertSame(['push', 'schedule', 'workflow_dispatch'], array_keys($workflow['on']));
        self::assertSame(['branches' => ['main'], 'paths' => ['docs/highlights.d/**', 'bin/highlights', 'config/gates/src/Highlight*.php', '.github/workflows/highlights.yml']], $workflow['on']['push']);
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

        self::assertSame(['index.md' => $escaped, 'journey/index.md' => $escaped], $this->assembledPages("# Highlights\n\nA code span `{{ x }}`, `{% if y %}` and `{{{%`.\n"));
    }

    public function testTheAssembledPageIsTheHighlightsUnchangedWhenTheyHoldNoLiquidOpening(): void
    {
        $highlights = "# Highlights\n\nBraces `{ x }`, closings `}}` and `%}`, a percent `%{`.";

        self::assertSame(['index.md' => $highlights, 'journey/index.md' => $highlights], $this->assembledPages($highlights));
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

    public function testOnlyTheRecordStepGetsTheJobTokenAndOnlyTheDockerHubLoginReadsASecret(): void
    {
        $workflow = $this->workflow();
        $build = $workflow['jobs']['build'];
        $steps = $build['steps'] ?? [];
        $record = array_first(array_filter($steps, static fn (array $step): bool => self::RECORD === trim($step['run'] ?? '')));
        self::assertNotNull($record);
        $isLogin = static fn (array $step): bool => self::LOGIN === ($step['run'] ?? null);
        $logins = array_values(array_filter($steps, $isLogin));

        self::assertSame(['GH_TOKEN' => '${{ github.token }}'], $record['env'] ?? null);
        self::assertArrayNotHasKey('GH_TOKEN', $workflow['env'] ?? []);
        self::assertArrayNotHasKey('GH_TOKEN', $build['env'] ?? []);
        self::assertCount(1, $logins);
        self::assertSame(['DOCKERHUB_USERNAME' => '${{ secrets.DOCKERHUB_USERNAME }}', 'DOCKERHUB_TOKEN' => '${{ secrets.DOCKERHUB_TOKEN }}'], $logins[0]['env'] ?? null);
        self::assertSame([], self::secretReferencesBesidesTheLogin($steps));
    }

    public function testASecretInBracketNotationIsFoundAsWellAsOneInDotNotation(): void
    {
        $login = ['run' => self::LOGIN, 'env' => ['DOCKERHUB_USERNAME' => '${{ secrets.DOCKERHUB_USERNAME }}', 'DOCKERHUB_TOKEN' => '${{ secrets.DOCKERHUB_TOKEN }}', 'EXTRA' => '${{ secrets[\'OTHER\'] }}']];
        $other = ['run' => 'echo "${{ secrets.ANOTHER }}"'];

        self::assertSame(["secrets['OTHER']", 'secrets.ANOTHER'], self::secretReferencesBesidesTheLogin([$login, $other]));
    }

    public function testTheAssembledPageReadsTheRecordedAnswersAsTheContainerSeesThem(): void
    {
        self::assertStringContainsString(' --recorded=/workspace/_journey-api ', $this->pageCommand());
    }

    public function testThePageStepFailsWhenTheEscapeFails(): void
    {
        self::assertNotSame(0, $this->runPageWrite("# Highlights\n", 'sed() { return 1; }; ')['status']);
    }

    // Only the login's two values are dropped, so any further secret, in dot or bracket notation, still counts.
    /**
     * @param list<array<string, mixed>> $steps
     *
     * @return list<string>
     */
    private static function secretReferencesBesidesTheLogin(array $steps): array
    {
        $withoutTheLoginValues = array_map(static function (array $step): array {
            if (self::LOGIN === ($step['run'] ?? null) && is_array($step['env'] ?? null)) {
                unset($step['env']['DOCKERHUB_USERNAME'], $step['env']['DOCKERHUB_TOKEN']);
            }

            return $step;
        }, $steps);
        $references = [];
        array_walk_recursive($withoutTheLoginValues, static function (mixed $value) use (&$references): void {
            if (is_string($value) && preg_match_all('/\bsecrets\s*(?:\.\s*\w+|\[[^\]]*\])/', $value, $matches) > 0) {
                array_push($references, ...$matches[0]);
            }
        });

        return $references;
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
        $filesystem->dumpFile($workspace.'/page/index.md', $highlights);
        $filesystem->dumpFile($workspace.'/page/journey/index.md', $highlights);

        try {
            $write = substr($this->pageCommand(), strlen(self::PAGES.' && '));
            $process = new Process(['sh', '-c', $prelude.str_replace([' /page/index.md', ' /page/journey/index.md'], [' '.escapeshellarg($workspace.'/page/index.md'), ' '.escapeshellarg($workspace.'/page/journey/index.md')], $write)], $workspace);
            $process->run();

            $pages = [];
            foreach (['index.md', 'journey/index.md'] as $page) {
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
