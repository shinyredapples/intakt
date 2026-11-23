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
        self::assertSame([], $this->secretReferencesBesidesTheLogin($workflow));
    }

    public function testASecretInBracketNotationIsFoundAsWellAsOneInDotNotation(): void
    {
        $login = ['run' => self::LOGIN, 'env' => ['DOCKERHUB_USERNAME' => '${{ secrets.DOCKERHUB_USERNAME }}', 'DOCKERHUB_TOKEN' => '${{ secrets.DOCKERHUB_TOKEN }}', 'EXTRA' => '${{ secrets[\'OTHER\'] }}']];
        $other = ['run' => 'echo "${{ secrets.ANOTHER }}"'];

        self::assertSame(["secrets['OTHER']", 'secrets.ANOTHER'], $this->secretReferencesBesidesTheLogin($this->withBuildSteps([$login, $other])));
    }

    public function testASecretReferenceInAnyLetterCaseIsFound(): void
    {
        $steps = [['env' => ['EXTRA' => '${{ SECRETS[\'EXTRA\'] }}', 'MORE' => '${{ Secrets.MORE }}']]];

        self::assertSame(["SECRETS['EXTRA']", 'Secrets.MORE'], $this->secretReferencesBesidesTheLogin($this->withBuildSteps($steps)));
    }

    public function testTheWholeSecretsContextIsFoundAsAnArgumentAsAnObjectAndInACondition(): void
    {
        $steps = [
            ['env' => ['ALL' => '${{ toJSON(secrets) }}', 'BARE' => '${{ secrets }}']],
            ['if' => "contains(toJSON(SECRETS), 'x')", 'run' => 'true'],
        ];

        self::assertSame(['toJSON(secrets)', 'secrets', "contains(toJSON(SECRETS), 'x')"], $this->secretReferencesBesidesTheLogin($this->withBuildSteps($steps)));
    }

    public function testTheWordSecretsOutsideAnExpressionOrAsPartOfAnotherNameIsNoReference(): void
    {
        $steps = [[
            'name' => 'Keep the secrets out of the log',
            'if' => "github.event.inputs.secrets_note != 'secrets'",
            'env' => ['HAS_SECRETS' => '${{ github.event.inputs.secrets }}', 'NOTE' => "\${{ format('no {0}', 'secrets') }}"],
            'run' => 'echo "no secrets here"',
        ]];

        self::assertSame([], $this->secretReferencesBesidesTheLogin($this->withBuildSteps($steps)));
    }

    public function testAValueUnderAKeyNamedIfOutsideAJobOrStepConditionIsAPlainString(): void
    {
        $steps = [['uses' => 'x', 'env' => ['if' => 'keep secrets out of the log'], 'with' => ['if' => 'secrets stay out']]];

        self::assertSame([], $this->secretReferencesBesidesTheLogin($this->withBuildSteps($steps)));
    }

    public function testAJobConditionAndAStepConditionReadingSecretsAreFound(): void
    {
        $workflow = ['jobs' => ['build' => ['steps' => [['if' => "secrets.STEP != ''", 'run' => 'true']], 'if' => "secrets.JOB != ''"]]];

        self::assertSame(["secrets.STEP != ''", "secrets.JOB != ''"], $this->secretReferencesBesidesTheLogin($workflow));
    }

    public function testASecretAnywhereOutsideTheStepsIsFound(): void
    {
        $workflow = ['env' => ['TOP' => '${{ secrets.TOP }}'], 'jobs' => ['build' => [
            'steps' => [['uses' => 'x', 'with' => ['token' => '${{ secrets.WITH }}']]],
            'if' => "secrets.CONDITION != ''",
            'env' => ['JOB' => '${{ secrets.JOB }}'],
            'container' => ['image' => 'x', 'credentials' => ['password' => '${{ secrets.CONTAINER }}']],
            'services' => ['db' => ['env' => ['PASSWORD' => '${{ secrets.SERVICE }}']]],
        ]]];

        self::assertSame(
            ['secrets.TOP', 'secrets.WITH', "secrets.CONDITION != ''", 'secrets.JOB', 'secrets.CONTAINER', 'secrets.SERVICE'],
            $this->secretReferencesBesidesTheLogin($workflow),
        );
    }

    public function testASecretAfterAQuotedClosingDelimiterIsFound(): void
    {
        $steps = [['run' => "echo \${{ format('}}{0}', secrets.EXTRA) }} and \${{ 'it''s }}' }}"]];

        self::assertSame(["format('}}{0}', secrets.EXTRA)"], $this->secretReferencesBesidesTheLogin($this->withBuildSteps($steps)));
    }

    public function testTheLoginValuesAreAllowedOnlyInTheBuildJob(): void
    {
        $login = ['run' => self::LOGIN, 'env' => ['DOCKERHUB_USERNAME' => '${{ secrets.DOCKERHUB_USERNAME }}', 'DOCKERHUB_TOKEN' => '${{ secrets.DOCKERHUB_TOKEN }}']];
        $workflow = ['jobs' => ['build' => ['steps' => [$login]], 'publish' => ['steps' => [$login]]]];

        self::assertSame(['secrets.DOCKERHUB_USERNAME', 'secrets.DOCKERHUB_TOKEN'], $this->secretReferencesBesidesTheLogin($workflow));
    }

    public function testTheAssembledPageReadsTheRecordedAnswersAsTheContainerSeesThem(): void
    {
        self::assertStringContainsString(' --recorded=/workspace/_journey-api ', $this->pageCommand());
    }

    public function testThePageStepFailsWhenTheEscapeFails(): void
    {
        self::assertNotSame(0, $this->runPageWrite("# Highlights\n", 'sed() { return 1; }; ')['status']);
    }

    // Only the build job's login loses its two values, so any other use of the secrets context, anywhere in the workflow, counts.
    /**
     * @return list<string>
     */
    private function secretReferencesBesidesTheLogin(mixed $node, string $path = ''): array
    {
        if (is_string($node)) {
            return array_values(array_filter($this->expressions($node, 1 === preg_match('#^/jobs/[^/]+(?:/steps/\d+)?/if$#', $path)), $this->readsSecrets(...)));
        }

        if (!is_array($node)) {
            return [];
        }

        if (1 === preg_match('#^/jobs/build/steps/\d+$#', $path) && self::LOGIN === ($node['run'] ?? null) && is_array($node['env'] ?? null)) {
            unset($node['env']['DOCKERHUB_USERNAME'], $node['env']['DOCKERHUB_TOKEN']);
        }

        $references = [];
        foreach ($node as $key => $child) {
            array_push($references, ...$this->secretReferencesBesidesTheLogin($child, $path.'/'.$key));
        }

        return $references;
    }

    /**
     * @param list<array<string, mixed>> $steps
     *
     * @return array<string, mixed>
     */
    private function withBuildSteps(array $steps): array
    {
        return ['jobs' => ['build' => ['steps' => $steps]]];
    }

    // GitHub reads a job or step if: as an expression even without ${{ }}, so it is scanned whole; other values only inside it.
    /**
     * @return list<string>
     */
    private function expressions(string $value, bool $isCondition): array
    {
        if ($isCondition && !str_contains($value, '${{')) {
            return [trim($value)];
        }

        $expressions = [];
        $offset = 0;
        while (false !== ($start = strpos($value, '${{', $offset))) {
            $end = $this->closingDelimiter($value, $start + 3);
            $expressions[] = trim(substr($value, $start + 3, $end - $start - 3));
            $offset = min($end + 2, strlen($value));
        }

        return $expressions;
    }

    // GitHub ends an expression at the first }} outside a '...' literal ('' in one is a quote), so a quoted }} cannot end it.
    private function closingDelimiter(string $value, int $from): int
    {
        $quoted = false;
        $length = strlen($value);
        for ($at = $from; $at < $length; ++$at) {
            if ("'" === $value[$at]) {
                $quoted = !$quoted;

                continue;
            }

            if (!$quoted && '}}' === substr($value, $at, 2)) {
                return $at;
            }
        }

        return $length;
    }

    // Contexts are case-insensitive and string literals are dropped first, so only the context itself counts, not a word.
    private function readsSecrets(string $expression): bool
    {
        $withoutLiterals = (string) preg_replace("/'(?:[^']|'')*'/", "''", $expression);

        return 1 === preg_match('/(?<![\w.-])secrets(?![\w-])/i', $withoutLiterals);
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
