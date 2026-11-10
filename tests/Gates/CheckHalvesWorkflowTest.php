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
    // Runs the half's own script in place of the container, from the test's folder, where bin/check is the stand-in below.
    private const string DOCKER = <<<'SH'
        #!/bin/sh
        for a; do shift; [ "$a" = .github/ci/check-half.sh ] && break; done
        exec sh "$REPO/.github/ci/check-half.sh" "$@"
        SH;

    // The static half ends only once the other half has started, so halves run one after the other fail with 9.
    private const string CHECK = <<<'SH'
        #!/bin/sh
        case $1 in
          --static-gates) echo '{"static":["cs","rector"],"order":["cs","phpstan","rector","tests"]}' ;;
          --only=cs,rector) i=0; until [ -e started ] || [ $i -ge 100 ]; do sleep 0.1; i=$((i+1)); done
            [ -e started ] || exit 9; echo '{"gate":"rector","ok":true}'; echo '{"gate":"cs","ok":true}'; exit "$STATIC_EXIT" ;;
          --skip=cs,rector) touch started; echo '{"gate":"tests","ok":true}'; echo '{"gate":"phpstan","ok":true}'; exit "$CODE_EXIT" ;;
          *) exit 8 ;;
        esac
        SH;

    #[TestWith(['0', '0', 0])]
    #[TestWith(['1', '0', 1])]
    #[TestWith(['0', '1', 1])]
    #[TestWith(['2', '1', 2])]
    #[TestWith(['1', '2', 2])]
    public function testTheTwoHalvesRunAtOnceAndPrintBothOutputsTheSummaryInGateOrderAndTheHigherExitCode(string $static, string $code, int $exit): void
    {
        $temp = sys_get_temp_dir().'/check-halves-'.bin2hex(random_bytes(4));
        new Filesystem()->dumpFile($temp.'/bin/docker', self::DOCKER);
        new Filesystem()->dumpFile($temp.'/bin/check', self::CHECK);
        chmod($temp.'/bin/docker', 0o755);
        chmod($temp.'/bin/check', 0o755);
        /** @var array{jobs: array{check: array{steps: list<array{name?: string, run?: string}>}}} $workflow */
        $workflow = Yaml::parseFile(__DIR__.'/../../.github/workflows/ci.yml');
        $step = array_column($workflow['jobs']['check']['steps'], 'run', 'name')['Run the gates'];

        $run = new ProcessRunner()->run(['bash', '--noprofile', '--norc', '-eo', 'pipefail', '-c', $step], $temp, [
            'PATH' => $temp.'/bin:'.getenv('PATH'), 'RUNNER_TEMP' => $temp, 'RUNNER_ENVIRONMENT' => 'self-hosted', 'REPO' => dirname(__DIR__, 2),
            'GITHUB_WORKSPACE' => $temp, 'CI_IMAGE' => 'image', 'EVENT' => 'push', 'STATIC_EXIT' => $static, 'CODE_EXIT' => $code,
        ]);
        new Filesystem()->remove($temp);

        $time = 'half: \d+s, memory.peak \S+, usage_usec \S+';
        self::assertMatchesRegularExpression("/^(static|code) half: bin\/check started\n(static|code) half: bin\/check started\n"
            ."== static half ==\ngate order: cs phpstan rector tests\nstatic half: bin\/check started\n".'\{"gate":"rector","ok":true\}'."\n".'\{"gate":"cs","ok":true\}'."\nstatic {$time}\n"
            ."== code half ==\ngate order: cs phpstan rector tests\ncode half: bin\/check started\n".'\{"gate":"tests","ok":true\}'."\n".'\{"gate":"phpstan","ok":true\}'."\ncode {$time}\n"
            .'== summary ==\n\{"gate":"cs","ok":true\}\n\{"gate":"phpstan","ok":true\}\n\{"gate":"rector","ok":true\}\n\{"gate":"tests","ok":true\}\n$/', $run->output);
        self::assertSame($exit, $run->exitCode);
    }
}
