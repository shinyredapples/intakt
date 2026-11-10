<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Job;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

// Runs the proxy sidecar's entrypoint with a stand-in tinyproxy, because the CI image has none and a skipped test fails the tests gate; the refusal lines of the real tinyproxy are in EgressProxyTest.
#[CoversNothing]
final class ProxyEntrypointTest extends TestCase
{
    private const string ENTRYPOINT = __DIR__.'/../../../docker/job-proxy/entrypoint.sh';

    private string $folder = '';

    protected function setUp(): void
    {
        $this->folder = sys_get_temp_dir().'/intakt-proxy-'.bin2hex(random_bytes(4));
        new Filesystem()->mkdir($this->folder.'/bin');
        file_put_contents($this->folder.'/bin/tinyproxy', "#!/bin/sh\nprintf '%s\\n' \"\$*\"\n");
        chmod($this->folder.'/bin/tinyproxy', 0o755);
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->folder);
    }

    public function testTheProxyRunsInTheForegroundAndDeniesEveryHostItsFilterDoesNotList(): void
    {
        $arguments = $this->entrypointRun('api.example');

        $config = (string) file_get_contents($this->folder.'/tinyproxy.conf');
        self::assertSame("-d -c {$this->folder}/tinyproxy.conf\n", $arguments);
        self::assertStringContainsString("Filter \"{$this->folder}/allow\"\nFilterDefaultDeny Yes\nFilterType ERE\n", $config);
        self::assertStringContainsString("Port 3128\n", $config);
        self::assertStringNotContainsString('LogFile', $config);
    }

    public function testEachAllowedHostBecomesAnAnchoredPatternWithItsDotsEscaped(): void
    {
        $this->entrypointRun('api.example,,registry.example');

        self::assertSame("^api\\.example$\n^registry\\.example$\n", file_get_contents($this->folder.'/allow'));
    }

    public function testOnlyTheAllowedHostsMatchTheFilter(): void
    {
        $this->entrypointRun('api.example,registry.example');

        $patterns = explode("\n", trim((string) file_get_contents($this->folder.'/allow')));
        $allowed = array_values(array_filter(
            ['api.example', 'registry.example', 'evil.example', 'api.example.evil.example', 'evil.api.example', 'apixexample'],
            static fn (string $host): bool => array_any($patterns, static fn (string $pattern): bool => 1 === preg_match('/'.$pattern.'/', $host)),
        ));

        self::assertSame(['api.example', 'registry.example'], $allowed);
    }

    private function entrypointRun(string $allow): string
    {
        $process = new Process(['sh', self::ENTRYPOINT], null, [
            'PATH' => $this->folder.'/bin:/usr/bin:/bin',
            'INTAKT_PROXY_ALLOW' => $allow,
            'INTAKT_PROXY_PORT' => '3128',
            'TMPDIR' => $this->folder,
        ]);
        $process->mustRun();

        return $process->getOutput();
    }
}
