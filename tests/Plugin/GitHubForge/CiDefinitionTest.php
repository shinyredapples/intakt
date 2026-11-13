<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubForge;

use Intakt\Plugin\GitHubForge\Domain\CiDefinition;
use Intakt\Plugin\GitHubForge\Domain\WorkflowFile;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CiDefinition::class)]
#[UsesClass(WorkflowFile::class)]
final class CiDefinitionTest extends TestCase
{
    private const string WORKFLOW = "jobs:\n  t:\n    steps:\n      - run: ./scripts/test.sh --all\n      - run: docker run image sh /workspace/tools/lint\n";

    public function testAScriptAWorkflowRunsIsACiChangeWithNoWorkflowFileChanged(): void
    {
        self::assertSame(['scripts/test.sh', 'tools/lint'], $this->ciPaths(['src/Foo.php', 'scripts/test.sh', 'tools/lint']));
    }

    public function testAPathWhoseTrailingPartsTheWorkflowNeverNamesIsNoCiChange(): void
    {
        self::assertSame([], $this->ciPaths(['src/Foo.php', 'scripts/test.sh.bak', 'scripts/old-test.sh', 'tools/linter', 'docs/Dockerfiles.md']));
    }

    public function testAFileNameNamedInAnyFolderMakesThatFileACiChangeInEveryFolder(): void
    {
        self::assertSame(['old-scripts/test.sh', 'test.sh', 'lint'], $this->ciPaths(['old-scripts/test.sh', 'test.sh', 'lint']));
    }

    public function testAFileNameIsMatchedInAnyCase(): void
    {
        self::assertSame(['scripts/Test.SH'], $this->ciPaths(['scripts/Test.SH']));
    }

    public function testAFileNameEndingASentenceIsStillNamed(): void
    {
        self::assertSame(['scripts/test.sh'], $this->ciPathsOf("jobs:\n  t:\n    steps:\n      - run: echo run test.sh.\n", ['scripts/test.sh']));
    }

    public function testEveryFileUnderTheGithubFolderIsACiChange(): void
    {
        self::assertSame(['.github/ci/build.sh', '.github/workflows/ci.yml'], $this->ciPaths(['.github/ci/build.sh', '.github/workflows/ci.yml']));
    }

    public function testADockerfileIsACiChangeWithoutBeingNamed(): void
    {
        self::assertSame(['Dockerfile', 'docker/ci.Dockerfile', 'docker/Dockerfile.ci', 'docker/dockerfile'], $this->ciPaths(['Dockerfile', 'docker/ci.Dockerfile', 'docker/Dockerfile.ci', 'docker/dockerfile']));
    }

    /** @return iterable<string, array{string}> */
    public static function relativeRuns(): iterable
    {
        yield 'a cd then the script' => ["jobs:\n  t:\n    steps:\n      - run: cd scripts && ./test.sh\n"];
        yield 'a parent step' => ["jobs:\n  t:\n    steps:\n      - run: ../test.sh\n        working-directory: scripts/ci\n"];
        yield 'a dot then a parent step' => ["jobs:\n  t:\n    steps:\n      - run: ./../test.sh\n        working-directory: scripts/ci\n"];
        yield 'the directory under defaults' => ["defaults:\n  run:\n    working-directory: scripts/ci\njobs:\n  t:\n    steps:\n      - run: ../test.sh\n"];
    }

    #[DataProvider('relativeRuns')]
    public function testAScriptRunThroughAnotherFolderIsACiChange(string $workflow): void
    {
        self::assertSame(['scripts/test.sh'], $this->ciPathsOf($workflow, ['scripts/test.sh', 'src/Foo.php']));
    }

    public function testEveryFileUnderALocalActionsFolderIsACiChange(): void
    {
        $workflow = "jobs:\n  t:\n    steps:\n      - uses: ./tools/build\n      - uses: 'actions/checkout@v4'\n";

        self::assertSame(['tools/build/action.yml', 'tools/build/lib/run.sh'], $this->ciPathsOf($workflow, ['tools/build/action.yml', 'tools/build/lib/run.sh', 'tools/builder/action.yml', 'tools/build.sh', 'checkout/action.yml']));
    }

    public function testAQuotedLocalActionCountsLikeAPlainOne(): void
    {
        self::assertSame(['tools/build/action.yml'], $this->ciPathsOf("jobs:\n  t:\n    steps:\n      - uses: \"./tools/build/\"\n", ['tools/build/action.yml', 'src/Foo.php']));
    }

    /** @return iterable<string, array{string}> */
    public static function actionsThatMayBeAnyFolder(): iterable
    {
        yield 'the root' => ['./'];
        yield 'a parent part' => ['./tools/../src'];
        yield 'an unclosed quote' => ['"./tools/my'];
        yield 'a value on the next line' => [''];
        yield 'a block value' => ['>-'];
        yield 'an expression' => ['${{ matrix.action }}'];
    }

    #[DataProvider('actionsThatMayBeAnyFolder')]
    public function testAnActionThatMayBeAnyFolderMakesEveryPathACiChange(string $action): void
    {
        self::assertSame(['src/Foo.php'], $this->ciPathsOf("jobs:\n  t:\n    steps:\n      - uses: {$action}\n", ['src/Foo.php']));
    }

    public function testARemoteActionMakesNoOtherPathACiChange(): void
    {
        $workflow = "jobs:\n  t:\n    steps:\n      - uses: actions/checkout@v4\n      - uses: \"github/codeql-action/init@v3\"\n      - uses: docker://alpine:3.20\n";

        self::assertSame([], $this->ciPathsOf($workflow, ['src/Foo.php']));
    }

    public function testEveryChangedPathIsACiChangeWhenAWorkflowCannotBeRead(): void
    {
        $paths = new CiDefinition()->changedAmong([new WorkflowFile('.github/workflows/ci.yml', self::WORKFLOW), new WorkflowFile('.github/workflows/other.yml', null)], ['src/Foo.php']);

        self::assertSame(['src/Foo.php'], $paths);
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
