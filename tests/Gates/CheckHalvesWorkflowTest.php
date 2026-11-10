<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\ProcessRunner;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

#[CoversNothing]
final class CheckHalvesWorkflowTest extends TestCase
{
    // The static half ends only once the other half has started, so halves run one after the other fail with 9.
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

    #[TestWith(['0', '0', 0])]
    #[TestWith(['1', '0', 1])]
    #[TestWith(['0', '1', 1])]
    #[TestWith(['2', '1', 2])]
    public function testTheTwoHalvesRunAtOnceAndPrintBothLogsAndTheHigherExitCode(string $static, string $code, int $exit): void
    {
        $temp = sys_get_temp_dir().'/check-halves-'.bin2hex(random_bytes(4));
        new Filesystem()->dumpFile($temp.'/bin/docker', self::DOCKER);
        chmod($temp.'/bin/docker', 0o755);
        /** @var array{jobs: array{check: array{steps: list<array{name?: string, run?: string}>}}} $workflow */
        $workflow = Yaml::parseFile(__DIR__.'/../../.github/workflows/ci.yml');
        $step = array_column($workflow['jobs']['check']['steps'], 'run', 'name')['Run the gates'];

        $run = new ProcessRunner()->run(['bash', '--noprofile', '--norc', '-eo', 'pipefail', '-c', $step], $temp, [
            'PATH' => $temp.'/bin:'.getenv('PATH'), 'RUNNER_TEMP' => $temp, 'RUNNER_ENVIRONMENT' => 'self-hosted',
            'GITHUB_WORKSPACE' => $temp, 'CI_IMAGE' => 'image', 'EVENT' => 'push', 'STATIC_EXIT' => $static, 'CODE_EXIT' => $code,
        ]);
        new Filesystem()->remove($temp);

        self::assertSame("static: --only --base=origin/main\ncode: --skip --base=origin/main\n", $run->output);
        self::assertSame($exit, $run->exitCode);
    }
}
