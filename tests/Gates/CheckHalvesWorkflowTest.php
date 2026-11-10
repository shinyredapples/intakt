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
    // docker runs the real half script; bin/check ends the static half only once the code half started, else with 9.
    private const array BIN = [
        'docker' => 'while [ "$1" != .github/ci/check-half.sh ]; do shift; done; shift; exec sh "$REPO/.github/ci/check-half.sh" "$@"',
        'check' => <<<'SH'
            case $1 in
              --static-gates) echo '{"static":["cs","rector"],"order":["cs","phpstan","rector","tests"]}' ;;
              --only=cs,rector) for i in $(seq 50); do [ -e started ] && break; sleep 0.1; done
                [ -e started ] || exit 9; echo '{"gate":"rector"}'; echo '{"gate":"cs"}'; exit "$STATIC" ;;
              --skip=cs,rector) touch started; echo '{"gate":"tests"}'; echo '{"gate":"phpstan"}'; exit "$CODE" ;;
            esac
            SH,
    ];

    #[TestWith(['0', '0', 0])]
    #[TestWith(['1', '0', 1])]
    #[TestWith(['0', '1', 1])]
    #[TestWith(['1', '2', 2])]
    public function testTheHalvesRunAtOnceAndPrintTheirOutputsTheGateLinesInOrderAndTheHigherExitCode(string $static, string $code, int $exit): void
    {
        $temp = sys_get_temp_dir().'/check-halves-'.bin2hex(random_bytes(4));

        foreach (self::BIN as $name => $script) {
            new Filesystem()->dumpFile($temp.'/bin/'.$name, "#!/bin/sh\n".$script."\n");
            chmod($temp.'/bin/'.$name, 0o755);
        }

        /** @var array{jobs: array{check: array{steps: list<array{name?: string, run?: string}>}}} $workflow */
        $workflow = Yaml::parseFile(__DIR__.'/../../.github/workflows/ci.yml');
        $run = new ProcessRunner()->run(['bash', '-eo', 'pipefail', '-c', array_column($workflow['jobs']['check']['steps'], 'run', 'name')['Run the gates']], $temp, [
            'PATH' => $temp.'/bin:'.getenv('PATH'), 'RUNNER_TEMP' => $temp, 'RUNNER_ENVIRONMENT' => '', 'GITHUB_WORKSPACE' => '', 'CI_IMAGE' => '',
            'EVENT' => 'push', 'REPO' => dirname(__DIR__, 2), 'STATIC' => $static, 'CODE' => $code,
        ]);
        new Filesystem()->remove($temp);

        [$first, $second, $rest] = explode("\n", (string) preg_replace('/ \d+s, memory\.peak \S+, usage_usec \S+$/m', '', $run->output), 3);
        $half = static fn (string $name, string $gates): string => "== {$name} half ==\ngate order: cs phpstan rector tests\n{$name} half: bin/check started\n{$gates}{$name} half:\n";
        self::assertEqualsCanonicalizing(['static half: bin/check started', 'code half: bin/check started'], [$first, $second]);
        self::assertSame($half('static', "{\"gate\":\"rector\"}\n{\"gate\":\"cs\"}\n").$half('code', "{\"gate\":\"tests\"}\n{\"gate\":\"phpstan\"}\n")
            ."== summary ==\n{\"gate\":\"cs\"}\n{\"gate\":\"phpstan\"}\n{\"gate\":\"rector\"}\n{\"gate\":\"tests\"}\n", $rest);
        self::assertSame($exit, $run->exitCode);
    }
}
