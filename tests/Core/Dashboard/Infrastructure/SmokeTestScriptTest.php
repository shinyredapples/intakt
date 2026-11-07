<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Infrastructure;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

// A stand-in docker on the PATH, because the sandbox and the unit tests have no Docker; the real checks run in CI (#1052).
#[CoversNothing]
final class SmokeTestScriptTest extends TestCase
{
    private const string FAKE_DOCKER = <<<'BASH'
        #!/bin/bash
        echo "$*" >> "$(dirname "$0")/calls"
        case "$1 $2" in
            "inspect --format") echo false ;;
            "run -d"*) echo container-id ;;
            "port "*) echo 127.0.0.1:1 ;;
            "buildx imagetools") echo "Digest: sha256:base" ;;
            "image inspect") echo sha256:image ;;
        esac
        BASH;

    // ADMIN_ON_ADDRESS models an admin API bound to every interface, because a Linux host reaches the container's address without a published port.
    private const string FAKE_RUNNING_DOCKER = <<<'BASH'
        #!/bin/bash
        case "$1" in
            port) exit 1 ;;
            inspect) echo "192.0.2.2 2001:db8::2 " ;;
            exec)
                [[ -n ${ADMIN_ON_ADDRESS:-} || $* != *192.0.2.2:2019* ]] || exit 7
                [[ -n ${ADMIN_ON_IPV6:-} || $* != *[[]2001:db8::2]:2019* ]] || exit 7
                ;;
        esac
        BASH;

    private const string FAKE_CURL = <<<'BASH'
        #!/bin/bash
        echo "$*" >> "$(dirname "$0")/curl-calls"
        printf '<title>Intakt</title>\n200'
        BASH;

    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/intakt-smoke-'.bin2hex(random_bytes(6));
        new Filesystem()->dumpFile($this->directory.'/docker', self::FAKE_DOCKER);
        chmod($this->directory.'/docker', 0o755);
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->directory);
    }

    public function testWithoutAnImageTheScriptPrintsItsUsageAndExitsWithTwo(): void
    {
        $process = $this->smokeTest();

        self::assertSame(2, $process->getExitCode());
        self::assertStringContainsString('usage:', $process->getErrorOutput());
    }

    public function testAContainerThatStopsFailsTheStepItStoppedIn(): void
    {
        $process = $this->smokeTest('intakt:test');

        self::assertSame(1, $process->getExitCode());
        self::assertStringContainsString("smoke test failed at step 'migrations'", $process->getErrorOutput());
    }

    public function testAFailedCheckStillRemovesTheContainerAndTheVolume(): void
    {
        $this->smokeTest('intakt:test');
        $calls = $this->dockerCalls();

        self::assertMatchesRegularExpression('/^rm -f intakt-smoke-\S+-web$/m', $calls);
        self::assertMatchesRegularExpression('/^volume rm -f intakt-smoke-\S+-data$/m', $calls);
    }

    public function testTheDigestsArePrintedOnTheirOwnLines(): void
    {
        $output = $this->smokeTest('intakt:test')->getOutput();

        self::assertStringContainsString("Image digest: sha256:image\n", $output);
        self::assertStringContainsString("Base image digest: sha256:base (dunglas/frankenphp:1.13.4-php8.5.12-trixie)\n", $output);
    }

    public function testTheSecretsReachTheContainerByNameOnly(): void
    {
        $this->smokeTest('intakt:test');

        self::assertMatchesRegularExpression('/^run -d .*-e APP_SECRET -e MERCURE_JWT_SECRET /m', $this->dockerCalls());
        self::assertDoesNotMatchRegularExpression('/SECRET=/', $this->dockerCalls());
    }

    public function testAnUnpublishedAdminApiThatAnswersOnTheContainersAddressFails(): void
    {
        $process = $this->adminUnpublishedCheck(['ADMIN_ON_ADDRESS' => '1']);

        self::assertSame(1, $process->getExitCode());
        self::assertStringContainsString("failed at step 'admin-unpublished': the admin API answers on 192.0.2.2:2019", $process->getErrorOutput());
    }

    public function testAnAdminApiThatAnswersOnTheIpv6AddressOnlyFails(): void
    {
        $process = $this->adminUnpublishedCheck(['ADMIN_ON_IPV6' => '1']);

        self::assertSame(1, $process->getExitCode());
        self::assertStringContainsString("failed at step 'admin-unpublished': the admin API answers on [2001:db8::2]:2019", $process->getErrorOutput());
    }

    public function testAnAdminApiThatAnswersOnLoopbackOnlyPasses(): void
    {
        $process = $this->adminUnpublishedCheck([]);

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertStringContainsString('nothing answers on 192.0.2.2:2019', $process->getOutput());
    }

    public function testTheImagesDashboardIsAskedUnderTheHostItAnswersAndReachedOnThePublishedPort(): void
    {
        $process = $this->dashboardCheck('image=intakt:test; web=test; volume=data; containers=(); start_container; check_dashboard "$origin"');

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertStringContainsString('--connect-to localhost:7781:127.0.0.1:1 http://localhost:7781/projects', $this->curlCalls());
    }

    public function testALocalDashboardIsAskedAtTheOriginAsGiven(): void
    {
        $process = $this->dashboardCheck('check_dashboard http://localhost:8000');

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertStringContainsString(' http://localhost:8000/projects', $this->curlCalls());
        self::assertStringNotContainsString('--connect-to', $this->curlCalls());
    }

    private function dashboardCheck(string $steps): Process
    {
        new Filesystem()->dumpFile($this->directory.'/curl', self::FAKE_CURL);
        chmod($this->directory.'/curl', 0o755);

        return $this->script(['bash', '-c', 'source docker/smoke-test.sh; '.$steps], []);
    }

    private function curlCalls(): string
    {
        $calls = file_get_contents($this->directory.'/curl-calls');
        self::assertIsString($calls);

        return $calls;
    }

    /**
     * @param array<string, string> $environment
     */
    private function adminUnpublishedCheck(array $environment): Process
    {
        new Filesystem()->dumpFile($this->directory.'/docker', self::FAKE_RUNNING_DOCKER);

        return $this->script(
            ['bash', '-c', 'source docker/smoke-test.sh; web=test; in_server=(docker exec test); check_admin_unpublished'],
            $environment,
        );
    }

    private function smokeTest(string ...$arguments): Process
    {
        return $this->script(['bash', 'docker/smoke-test.sh', ...array_values($arguments)], []);
    }

    /**
     * @param list<string>          $command
     * @param array<string, string> $environment
     */
    private function script(array $command, array $environment): Process
    {
        $process = new Process($command, dirname(__DIR__, 4), ['PATH' => $this->directory.':'.getenv('PATH'), ...$environment]);
        $process->run();

        return $process;
    }

    private function dockerCalls(): string
    {
        $calls = file_get_contents($this->directory.'/calls');
        self::assertIsString($calls);

        return $calls;
    }
}
