<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Infrastructure;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

// Stand-ins on the PATH, because the sandbox and the unit tests have no Docker; the real check runs in CI's image job.
#[CoversNothing]
final class VerifyUpgradeScriptTest extends TestCase
{
    private const string FAKE_DOCKER = <<<'BASH'
        #!/bin/bash
        here=$(dirname "$0")
        echo "$*" >> "$here/calls"
        case "$1" in
            build) cat > /dev/null ;;
            inspect) echo healthy ;;
            exec) [[ -e $here/changing-facts && $3 == cat ]] && wc -l < "$here/calls" ;;
        esac
        exit 0
        BASH;

    private const string FAKE_GIT = <<<'BASH'
        #!/bin/bash
        echo "git $*" >> "$(dirname "$0")/calls"
        BASH;

    private const string FAKE_OPENSSL = <<<'BASH'
        #!/bin/bash
        echo 00
        BASH;

    private const string BASE_ON_ORIGINAL = 'run --detach .* --volume intakt-upgrade-check-original:/app/var/data intakt:upgrade-base';
    private const string HEAD_ON_COPY = 'run --detach .* --volume intakt-upgrade-check-copy:/app/var/data intakt:ci';

    private string $directory;

    public function testWithoutAHeadImageAndABaseCommitTheScriptPrintsItsUsageAndExitsWithTwo(): void
    {
        $process = $this->verifyUpgrade('intakt:ci');

        self::assertSame(2, $process->getExitCode());
        self::assertStringContainsString('usage:', $process->getErrorOutput());
    }

    public function testTheBaseIsBuiltFromTheBaseCommitAndTheHeadImageIsNotBuilt(): void
    {
        $process = $this->verifyUpgrade('intakt:ci', 'abc123');

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        // git and docker run side by side in a pipe, so their lines can land in either order.
        self::assertStringContainsString("git archive abc123\n", $this->calls());
        self::assertStringContainsString("\nbuild --tag intakt:upgrade-base -\n", $this->calls());
        self::assertSame(1, substr_count($this->calls(), "\nbuild "));
    }

    public function testTheHeadStartsOnTheCopyThenTheBaseStartsAgainOnTheUntouchedOriginal(): void
    {
        $this->verifyUpgrade('intakt:ci', 'abc123');

        self::assertMatchesRegularExpression('#'.self::BASE_ON_ORIGINAL.'.*\n(.*\n)*'.self::HEAD_ON_COPY.'\n(.*\n)*'.self::BASE_ON_ORIGINAL.'#', $this->calls());
        self::assertStringContainsString("run --rm --user root --entrypoint cp --volume intakt-upgrade-check-original:/from:ro --volume intakt-upgrade-check-copy:/to intakt:upgrade-base -a /from/. /to/\n", $this->calls());
        self::assertStringContainsString("exec intakt-upgrade-check php bin/console doctrine:migrations:up-to-date\n", $this->calls());
    }

    public function testFactsTheHeadReadsOtherwiseFailTheCheckBeforeTheRollback(): void
    {
        // A marker file, because the fake docker cannot tell the base's start from the head's: with it, each read of the facts differs.
        touch($this->directory.'/changing-facts');

        $process = $this->verifyUpgrade('intakt:ci', 'abc123');

        self::assertSame(1, $process->getExitCode());
        self::assertStringContainsString('the upgrade start reads other facts than the base wrote', $process->getErrorOutput());
        self::assertSame(1, preg_match_all('#'.self::BASE_ON_ORIGINAL.'#', $this->calls()));
    }

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/intakt-upgrade-'.bin2hex(random_bytes(6));
        foreach (['docker' => self::FAKE_DOCKER, 'git' => self::FAKE_GIT, 'openssl' => self::FAKE_OPENSSL] as $command => $script) {
            new Filesystem()->dumpFile($this->directory.'/'.$command, $script);
            chmod($this->directory.'/'.$command, 0o755);
        }
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->directory);
    }

    private function verifyUpgrade(string ...$arguments): Process
    {
        $process = new Process(['bash', 'docker/verify-upgrade.sh', ...array_values($arguments)], dirname(__DIR__, 4), ['PATH' => $this->directory.':'.getenv('PATH')]);
        $process->run();

        return $process;
    }

    private function calls(): string
    {
        $calls = file_get_contents($this->directory.'/calls');
        self::assertIsString($calls);

        return $calls;
    }
}
