<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\ProcessResult;
use PHPUnit\Framework\Attributes\CoversNothing;

#[CoversNothing]
final class PersistenceEnginesTest extends SandboxTestCase
{
    private const string SCRIPT = __DIR__.'/../../.github/ci/persistence-engines.sh';

    // A fake docker, because the real engines need the runner's service containers: it notes when each engine started
    // and ended so the test can prove they overlap, and exits with the code a fail.<network> file names.
    private const string FAKE_DOCKER = <<<'BASH'
        #!/usr/bin/env bash
        while [ "$#" -gt 0 ]; do
          if [ "$1" = --network ]; then network=$2; fi
          shift
        done
        date +%s.%N > "$MARKS/start.$network"
        sleep 1
        echo "tests ran on $network"
        date +%s.%N > "$MARKS/end.$network"
        if [ -f "$MARKS/fail.$network" ]; then exit "$(cat "$MARKS/fail.$network")"; fi
        exit 0
        BASH;

    public function testEveryEngineRunsAtOnceAndAllPassingSucceeds(): void
    {
        $sandbox = $this->sandboxWithFakeDocker();

        $result = $this->runEngines($sandbox);

        self::assertTrue($result->succeeded(), $result->output.$result->errors);
        foreach (['one', 'two', 'three'] as $network) {
            self::assertStringContainsString("::group::persistence on engine {$network} (exit 0)\ntests ran on {$network}\n::endgroup::", $result->output);
        }
        $starts = array_map(fn (string $network): float => $this->mark($sandbox, 'start', $network), ['one', 'two', 'three']);
        $ends = array_map(fn (string $network): float => $this->mark($sandbox, 'end', $network), ['one', 'two', 'three']);
        self::assertLessThan(min($ends), max($starts), 'every engine started before any engine finished');
    }

    public function testAFailingEngineFailsTheStepWithItsExitCodeWhileTheOthersFinish(): void
    {
        $sandbox = $this->sandboxWithFakeDocker();
        $sandbox->write('marks/fail.two', '3');

        $result = $this->runEngines($sandbox);

        self::assertSame(1, $result->exitCode, $result->output.$result->errors);
        self::assertStringContainsString('::group::persistence on engine two (exit 3)', $result->output);
        self::assertStringContainsString('::error::persistence on engine two failed (exit 3)', $result->output);
        self::assertStringContainsString('::group::persistence on engine one (exit 0)', $result->output);
        self::assertStringContainsString('::group::persistence on engine three (exit 0)', $result->output);
        self::assertFileExists($sandbox->root.'/marks/end.one');
        self::assertFileExists($sandbox->root.'/marks/end.three');
    }

    private function sandboxWithFakeDocker(): Sandbox
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('bin/docker', self::FAKE_DOCKER);
        chmod($sandbox->root.'/bin/docker', 0o755);
        $sandbox->write('marks/.keep', '');

        return $sandbox;
    }

    // Under the runner's own shell options (bash -e with pipefail), because under -e a failing engine once lost its exit code.
    private function runEngines(Sandbox $sandbox): ProcessResult
    {
        return $sandbox->runScript(
            'set -eo pipefail; PATH="$PWD/bin:$PATH" '.escapeshellarg(self::SCRIPT)
            .' "engine one|one|sqlite:///one" "engine two|two|sqlite:///two" "engine three|three|sqlite:///three"',
            ['CI_IMAGE' => 'intakt-ci:test', 'GITHUB_WORKSPACE' => $sandbox->root, 'MARKS' => $sandbox->root.'/marks'],
        );
    }

    private function mark(Sandbox $sandbox, string $kind, string $network): float
    {
        return (float) trim((string) file_get_contents($sandbox->root."/marks/{$kind}.{$network}"));
    }
}
