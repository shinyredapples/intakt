<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\ProcessRunner;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

#[CoversNothing]
final class CheckHalvesWorkflowTest extends TestCase
{
    // The static half ends only once the other half has started, so a run of one half after the other fails with 9.
    private const string DOCKER = <<<'SH'
        #!/bin/sh
        case "$*" in
          *' sh --only '*) h=static; i=0; until [ -e "$RUNNER_TEMP/code" ] || [ $i -ge 100 ]; do sleep 0.1; i=$((i+1)); done
            [ -e "$RUNNER_TEMP/code" ] || exit 9 ;;
          *) h=code; touch "$RUNNER_TEMP/code" ;;
        esac
        echo "$h: ${*##* sh }"
        [ $h = static ] && exit "$STATIC_EXIT"; exit "$CODE_EXIT"
        SH;

    /** @return iterable<string, array{string, string, int}> */
    public static function exits(): iterable
    {
        yield 'both pass' => ['0', '0', 0];
        yield 'a red static gate' => ['1', '0', 1];
        yield 'a red test' => ['0', '1', 1];
        yield 'a usage error beside a red test' => ['2', '1', 2];
    }

    #[DataProvider('exits')]
    public function testTheTwoHalvesRunAtOnceAndPrintBothLogsAndTheHigherExitCode(string $static, string $code, int $exit): void
    {
        $temp = sys_get_temp_dir().'/check-halves-'.bin2hex(random_bytes(4));
        $filesystem = new Filesystem();
        $filesystem->dumpFile($temp.'/bin/docker', self::DOCKER);
        $filesystem->chmod($temp.'/bin/docker', 0o755);

        $run = new ProcessRunner()->run(['bash', '--noprofile', '--norc', '-eo', 'pipefail', '-c', $this->step()], $temp, [
            'PATH' => $temp.'/bin:'.getenv('PATH'), 'RUNNER_TEMP' => $temp, 'RUNNER_ENVIRONMENT' => 'self-hosted',
            'GITHUB_WORKSPACE' => $temp, 'CI_IMAGE' => 'image', 'EVENT' => 'push', 'STATIC_EXIT' => $static, 'CODE_EXIT' => $code,
        ]);
        $filesystem->remove($temp);

        self::assertSame("static: --only --base=origin/main\ncode: --skip --base=origin/main\n", $run->output);
        self::assertSame($exit, $run->exitCode);
    }

    private function step(): string
    {
        /** @var array{jobs: array{check: array{steps: list<array{name?: string, run?: string}>}}} $workflow */
        $workflow = Yaml::parseFile(__DIR__.'/../../.github/workflows/ci.yml');

        return array_column($workflow['jobs']['check']['steps'], 'run', 'name')['Run the gates'];
    }
}
