<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\ProcessResult;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversNothing]
final class PrRunPassedTest extends SandboxTestCase
{
    private const string SCRIPT = __DIR__.'/../../.github/ci/pr-run-passed.sh';

    // A fake gh, so the test needs no network: it answers both API calls from files through --jq, later pages only with --paginate.
    private const string FAKE_GH = <<<'SH'
        #!/bin/sh
        set -eu
        if [ -e fail ]; then echo 'gh: Not Found (HTTP 404)' >&2; exit 1; fi
        paginate=no
        if [ "$2" = --paginate ]; then paginate=yes; shift; fi
        case "$2" in
          'repos/example/intakt/actions/workflows/ci.yml/runs?head_sha=abc&event=pull_request&status=success&per_page=100') page=runs ;;
          'repos/example/intakt/actions/runs/'*'/artifacts?name=tested-tree-pushed&per_page=100') run=${2%/artifacts*}; page=artifacts-${run##*/} ;;
          *) exit 3 ;;
        esac
        jq -r "$4" "$page.json"
        if [ "$paginate" = no ]; then exit 0; fi
        for later in "$page"-*.json; do
          if [ -e "$later" ]; then jq -r "$4" "$later"; fi
        done
        SH;

    /** @return iterable<string, array{string, list<array{string, bool}>, string}> */
    public static function runs(): iterable
    {
        yield 'a run that tested the pushed tree' => ['example/intakt', [['tested-tree-pushed', false]], 'yes'];
        yield 'a run that tested another tree (another base branch, or an older base)' => ['example/intakt', [['tested-tree-merged', false]], 'no'];
        yield 'a run whose record expired' => ['example/intakt', [['tested-tree-pushed', true]], 'no'];
        yield 'a run with no record' => ['example/intakt', [], 'no'];
        yield 'a run whose artifact name only ends with the pushed tree\'s record' => ['example/intakt', [['report tested-tree-pushed', false]], 'no'];
        yield 'a fork\'s run that tested the pushed tree' => ['fork/intakt', [['tested-tree-pushed', false]], 'no'];
    }

    /** @param list<array{string, bool}> $artifacts */
    #[DataProvider('runs')]
    public function testOnlyARunFromThisRepositoryThatTestedThePushedTreeCounts(string $repository, array $artifacts, string $expected): void
    {
        $sandbox = $this->sandboxWithRuns([['id' => 7, 'head_repository' => ['full_name' => $repository]]]);
        $sandbox->write('artifacts-7.json', $this->json(['artifacts' => array_map(
            static fn (array $artifact): array => ['name' => $artifact[0], 'expired' => $artifact[1]],
            $artifacts,
        )]));

        $result = $this->answer($sandbox);

        self::assertTrue($result->succeeded(), $result->errors);
        self::assertSame($expected."\n", $result->output);
    }

    public function testNoSuccessfulRunMeansNo(): void
    {
        $result = $this->answer($this->sandboxWithRuns([]));

        self::assertTrue($result->succeeded(), $result->errors);
        self::assertSame("no\n", $result->output);
    }

    public function testASecondRunThatTestedThePushedTreeCounts(): void
    {
        $sandbox = $this->sandboxWithRuns([
            ['id' => 7, 'head_repository' => ['full_name' => 'example/intakt']],
            ['id' => 8, 'head_repository' => ['full_name' => 'example/intakt']],
        ]);
        $sandbox->write('artifacts-7.json', $this->json(['artifacts' => [['name' => 'tested-tree-merged', 'expired' => false]]]));
        $sandbox->write('artifacts-8.json', $this->json(['artifacts' => [['name' => 'tested-tree-pushed', 'expired' => false]]]));

        self::assertSame("yes\n", $this->answer($sandbox)->output);
    }

    public function testARunOnALaterPageCounts(): void
    {
        $sandbox = $this->sandboxWithRuns([['id' => 7, 'head_repository' => ['full_name' => 'example/intakt']]]);
        $sandbox->write('runs-2.json', $this->json(['workflow_runs' => [['id' => 8, 'head_repository' => ['full_name' => 'example/intakt']]]]));
        $sandbox->write('artifacts-7.json', $this->json(['artifacts' => [['name' => 'tested-tree-merged', 'expired' => false]]]));
        $sandbox->write('artifacts-8.json', $this->json(['artifacts' => [['name' => 'tested-tree-pushed', 'expired' => false]]]));

        $result = $this->answer($sandbox);

        self::assertTrue($result->succeeded(), $result->errors);
        self::assertSame("yes\n", $result->output);
    }

    public function testARecordOnALaterPageOfARunsArtifactsCounts(): void
    {
        $sandbox = $this->sandboxWithRuns([['id' => 7, 'head_repository' => ['full_name' => 'example/intakt']]]);
        $sandbox->write('artifacts-7.json', $this->json(['artifacts' => [['name' => 'tested-tree-merged', 'expired' => false]]]));
        $sandbox->write('artifacts-7-2.json', $this->json(['artifacts' => [['name' => 'tested-tree-pushed', 'expired' => false]]]));

        $result = $this->answer($sandbox);

        self::assertTrue($result->succeeded(), $result->errors);
        self::assertSame("yes\n", $result->output);
    }

    public function testAFailingApiCallExitsNonZeroAndPrintsNoAnswer(): void
    {
        $sandbox = $this->sandboxWithRuns([]);
        $sandbox->write('fail', '');

        $result = $this->answer($sandbox);

        self::assertFalse($result->succeeded());
        self::assertSame('', $result->output);
    }

    public function testAFailingArtifactsCallExitsNonZeroAndPrintsNoAnswer(): void
    {
        // The run has no artifacts file, so the fake gh fails like an API error.
        $result = $this->answer($this->sandboxWithRuns([['id' => 7, 'head_repository' => ['full_name' => 'example/intakt']]]));

        self::assertFalse($result->succeeded());
        self::assertSame('', $result->output);
    }

    public function testAWrongArgumentCountIsAUsageError(): void
    {
        $result = $this->sandboxWithRuns([])->runScript('chmod +x bin/gh; PATH="$PWD/bin:$PATH"; sh '.escapeshellarg(self::SCRIPT).' abc');

        self::assertSame(2, $result->exitCode);
        self::assertSame('', $result->output);
    }

    /** @param list<array<string, mixed>> $runs */
    private function sandboxWithRuns(array $runs): Sandbox
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('bin/gh', self::FAKE_GH);
        $sandbox->write('runs.json', $this->json(['workflow_runs' => $runs]));

        return $sandbox;
    }

    /** @param array<string, mixed> $value */
    private function json(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR);
    }

    private function answer(Sandbox $sandbox): ProcessResult
    {
        return $sandbox->runScript(
            'chmod +x bin/gh; PATH="$PWD/bin:$PATH"; sh '.escapeshellarg(self::SCRIPT).' abc pushed',
            ['GITHUB_REPOSITORY' => 'example/intakt'],
        );
    }
}
