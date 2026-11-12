<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubForge;

use Intakt\Plugin\GitHubForge\Domain\CiDefinition;
use Intakt\Plugin\GitHubForge\Domain\WorkflowFile;
use Intakt\Plugin\GitHubForge\Domain\WorkflowValue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CiDefinition::class)]
#[UsesClass(WorkflowFile::class)]
#[UsesClass(WorkflowValue::class)]
final class CiDefinitionTest extends TestCase
{
    private const string WORKFLOW = "jobs:\n  t:\n    steps:\n      - run: ./scripts/test.sh --all\n      - run: docker run image sh /workspace/tools/lint\n";

    public function testAScriptAWorkflowRunsIsACiChangeWithNoWorkflowFileChanged(): void
    {
        self::assertSame(['scripts/test.sh', 'tools/lint'], $this->ciPaths(['src/App.php', 'scripts/test.sh', 'tools/lint']));
    }

    public function testEveryFileUnderTheGithubFolderIsACiChange(): void
    {
        self::assertSame(['.github/ci/build.sh', '.github/workflows/ci.yml'], $this->ciPaths(['.github/ci/build.sh', '.github/workflows/ci.yml']));
    }

    public function testADockerfileIsACiChangeWithoutBeingNamed(): void
    {
        self::assertSame(['Dockerfile', 'docker/ci.Dockerfile', 'docker/Dockerfile.ci'], $this->ciPaths(['Dockerfile', 'docker/ci.Dockerfile', 'docker/Dockerfile.ci']));
    }

    public function testAPathThatOnlyStartsOrEndsLikeANamedScriptIsNoCiChange(): void
    {
        self::assertSame([], $this->ciPaths(['scripts/test.sh.bak', 'tools/linter', 'old-scripts/test.sh', 'docs/Dockerfiles.md']));
    }

    public function testAScriptRunFromAWorkingDirectoryIsACiChange(): void
    {
        $workflow = "jobs:\n  t:\n    steps:\n      - run: ./test.sh\n        working-directory: ./scripts/ # the scripts\n";

        self::assertSame(['scripts/test.sh'], $this->ciPathsOf($workflow, ['scripts/test.sh', 'tools/test.sh', 'test.sh.bak']));
    }

    public function testAScriptRunFromAWorkingDirectoryOfAnExpressionIsACiChangeInAnyFolder(): void
    {
        $workflow = "defaults:\n  run:\n    working-directory: \${{ github.workspace }}/scripts\njobs:\n  t:\n    steps:\n      - run: ./test.sh\n";

        self::assertSame(['scripts/test.sh', 'tools/test.sh'], $this->ciPathsOf($workflow, ['scripts/test.sh', 'tools/test.sh', 'scripts/old-test.sh']));
    }

    /** @return iterable<string, array{string}> */
    public static function parentRelativeRuns(): iterable
    {
        yield 'a parent step' => ["jobs:\n  t:\n    steps:\n      - run: ../test.sh\n        working-directory: scripts/ci\n"];
        yield 'a dot then a parent step' => ["jobs:\n  t:\n    steps:\n      - run: ./../test.sh\n        working-directory: scripts/ci\n"];
        yield 'the directory under defaults' => ["defaults:\n  run:\n    working-directory: scripts/ci\njobs:\n  t:\n    steps:\n      - run: ../test.sh\n"];
        yield 'a parent step inside a script' => ["jobs:\n  t:\n    steps:\n      - run: cd .. && ./test.sh\n        working-directory: scripts/ci\n"];
        yield 'a parent directory' => ["jobs:\n  t:\n    steps:\n      - run: ./test.sh\n        working-directory: scripts/ci/..\n"];
    }

    #[DataProvider('parentRelativeRuns')]
    public function testAScriptRunThroughAParentPathIsACiChange(string $workflow): void
    {
        self::assertSame(['scripts/test.sh'], $this->ciPathsOf($workflow, ['scripts/test.sh', 'src/App.php']));
    }

    public function testAWorkingDirectoryEndingInADotComponentIsACiChangeInAnyFolder(): void
    {
        $workflow = "jobs:\n  t:\n    steps:\n      - run: ./test.sh\n        working-directory: scripts/.\n";

        self::assertSame(['scripts/test.sh', 'tools/test.sh'], $this->ciPathsOf($workflow, ['scripts/test.sh', 'tools/test.sh', 'src/App.php']));
    }

    public function testAQuotedWorkingDirectoryIsReadAsAPlainOne(): void
    {
        $workflow = "jobs:\n  t:\n    steps:\n      - run: ./test.sh\n        working-directory: './scripts'\n";

        self::assertSame(['scripts/test.sh'], $this->ciPathsOf($workflow, ['scripts/test.sh', 'tools/test.sh']));
    }

    public function testEveryFileUnderALocalActionsFolderIsACiChange(): void
    {
        $workflow = "jobs:\n  t:\n    steps:\n      - uses: ./tools/build\n      - uses: 'actions/checkout@v4'\n";

        self::assertSame(['tools/build/action.yml', 'tools/build/lib/run.sh'], $this->ciPathsOf($workflow, ['tools/build/action.yml', 'tools/build/lib/run.sh', 'tools/builder/action.yml', 'tools/build.sh', 'checkout/action.yml']));
    }

    public function testAQuotedLocalActionCountsLikeAPlainOne(): void
    {
        self::assertSame(['tools/build/action.yml'], $this->ciPathsOf("jobs:\n  t:\n    steps:\n      - uses: \"./tools/build\"\n", ['tools/build/action.yml', 'src/App.php']));
    }

    public function testALocalActionAtTheRootMakesEveryPathACiChange(): void
    {
        self::assertSame(['src/App.php'], $this->ciPathsOf("jobs:\n  t:\n    steps:\n      - uses: ./\n", ['src/App.php']));
    }

    public function testEveryChangedPathIsACiChangeWhenAWorkflowCannotBeRead(): void
    {
        $paths = new CiDefinition()->changedAmong([new WorkflowFile('.github/workflows/ci.yml', null)], ['src/App.php']);

        self::assertSame(['src/App.php'], $paths);
    }

    /**
     * @param list<string> $changed
     *
     * @return list<string>
     */
    private function ciPaths(array $changed): array
    {
        return $this->ciPathsOf(self::WORKFLOW, $changed);
    }

    /**
     * @param list<string> $changed
     *
     * @return list<string>
     */
    private function ciPathsOf(string $workflow, array $changed): array
    {
        return new CiDefinition()->changedAmong([new WorkflowFile('.github/workflows/ci.yml', $workflow)], $changed);
    }
}
