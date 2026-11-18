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

    private string $directory;

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
        self::assertStringContainsString('Base image digest: sha256:base (dunglas/frankenphp:', $output);
    }

    // A megabyte after the digest outlasts any pipe buffer, so a reader that stops at the digest is gone while docker still writes.
    public function testTheBaseDigestIsReadWhenImagetoolsWritesFarMoreAfterIt(): void
    {
        $process = $this->digestsStep('echo "Digest: sha256:base"; seq 200000');

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertStringContainsString('Base image digest: sha256:base (dunglas/frankenphp:', $process->getOutput());
    }

    public function testAFailingImagetoolsFailsTheDigestsStepWithItsOwnError(): void
    {
        $process = $this->digestsStep('echo "ERROR: manifest unknown" >&2; exit 1');

        self::assertSame(1, $process->getExitCode());
        self::assertStringContainsString("ERROR: manifest unknown\nsmoke test failed at step 'digests': the base image tag", $process->getErrorOutput());
    }

    public function testThePublishedPortIsReadWhenDockerPortWritesFarMoreAfterIt(): void
    {
        new Filesystem()->dumpFile($this->directory.'/docker', "#!/bin/bash\n".'[[ $1 == port ]] || exit 0; echo 127.0.0.1:1; seq 200000');

        $process = $this->script(['bash', '-c', 'source docker/smoke-test.sh; image=i; web=w; volume=v; start_container'], []);

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertStringContainsString("started w on http://127.0.0.1:1\n", $process->getOutput());
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

    public function testTheImagesDashboardIsAskedAsLocalhost7781(): void
    {
        $this->dashboardCheck('image=i; web=w; volume=v; start_container; check_dashboard "$origin"');

        self::assertStringContainsString('--header Host: localhost:7781 http://127.0.0.1:1/projects', $this->dockerCalls());
    }

    public function testALocalDashboardIsAskedAsItsOrigin(): void
    {
        $this->dashboardCheck('check_dashboard http://localhost:8000');

        self::assertMatchesRegularExpression('#^curl (?!.*--header).* http://localhost:8000/projects$#m', $this->dockerCalls());
    }

    // The docker stub closes descriptor 3 because a container starts without it, which is why bin/instance failed in CI.
    public function testTheInstanceStatusIsReadFromDescriptorThree(): void
    {
        foreach (['docker' => 'e=$6; shift 7; exec 3>&- "$e" "$@"', 'php' => "echo '{\"status\":\"ready\",\"mode\":\"worker\"}' >&3"] as $name => $body) {
            new Filesystem()->dumpFile($this->directory.'/'.$name, "#!/bin/bash\n".$body);
            chmod($this->directory.'/'.$name, 0o755);
        }

        $process = $this->script(['bash', '-c', 'source docker/smoke-test.sh; image=i; smoke=s; check_instance'], []);

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
    }

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

    // The script path as $0 lets print_digests find the Dockerfile; sourced as ./ it does not count as run, so main stays out.
    private function digestsStep(string $imagetools): Process
    {
        new Filesystem()->dumpFile($this->directory.'/docker', "#!/bin/bash\n".'[[ $1 == buildx ]] || { echo sha256:image; exit; }; '.$imagetools);

        return $this->script(['bash', '-c', 'source ./docker/smoke-test.sh; image=i; print_digests', 'docker/smoke-test.sh'], []);
    }

    private function dashboardCheck(string $steps): Process
    {
        new Filesystem()->dumpFile($this->directory.'/curl', "#!/bin/bash\n".'echo "curl $*" >> "${0%/*}/calls"; printf "<title>Intakt</title>\n200"');
        chmod($this->directory.'/curl', 0o755);

        return $this->script(['bash', '-c', 'source docker/smoke-test.sh; '.$steps], []);
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
