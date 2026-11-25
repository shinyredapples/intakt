<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Server;

use Intakt\Core\Server\Contract\AdminApiNotOnLocalhost;
use Intakt\Core\Server\Contract\EndpointDown;
use Intakt\Core\Server\Contract\HubHealth;
use Intakt\Core\Server\Contract\HubVerdict;
use Intakt\Core\Server\Contract\InfrastructureCheck;
use Intakt\Core\Server\Contract\InfrastructureChecks;
use Intakt\Core\Server\Contract\ServerMetrics;
use Intakt\Core\Server\Contract\ServerReading;
use Intakt\Core\Server\Contract\ServerStatus;
use Intakt\Core\Server\Contract\WorkerThreads;
use Intakt\Core\Server\Infrastructure\AdminApi;
use Intakt\Core\Server\Infrastructure\AdminApiServerStatus;
use Intakt\Core\Server\Infrastructure\PrometheusSamples;
use Intakt\Core\Server\UseCase\Doctor\DoctorCommand;
use Intakt\Plugin\GitHubForge\Contract\ForgeConnectionVerdict;
use Intakt\Plugin\GitHubForge\Domain\ExpectedInstallation;
use Intakt\Plugin\GitHubForge\Infrastructure\AppJwt;
use Intakt\Plugin\GitHubForge\Infrastructure\ForgeConnectionCheck;
use Intakt\Plugin\GitHubForge\Infrastructure\RateLimitReadings;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionMethod;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

#[CoversClass(DoctorCommand::class)]
#[UsesClass(AdminApi::class)]
#[UsesClass(AdminApiServerStatus::class)]
#[UsesClass(PrometheusSamples::class)]
#[UsesClass(ServerReading::class)]
#[UsesClass(WorkerThreads::class)]
#[UsesClass(ServerMetrics::class)]
#[UsesClass(EndpointDown::class)]
#[UsesClass(AdminApiNotOnLocalhost::class)]
#[UsesClass(InfrastructureChecks::class)]
#[UsesClass(HubVerdict::class)]
#[UsesClass(InfrastructureCheck::class)]
#[UsesClass(ForgeConnectionCheck::class)]
#[UsesClass(ForgeConnectionVerdict::class)]
#[UsesClass(ExpectedInstallation::class)]
#[UsesClass(AppJwt::class)]
#[UsesClass(RateLimitReadings::class)]
final class DoctorCommandTest extends TestCase
{
    private const string THREADS_FIX = 'check that FrankenPHP is running and that FRANKENPHP_ADMIN_URL points at its admin API';
    private const string METRICS_FIX = "check that the Caddyfile's global options turn on metrics and that FRANKENPHP_ADMIN_URL points at the admin API";
    private const string HUB_DOWN_FIX = "check that FrankenPHP runs with the Caddyfile's mercure block and that FRANKENPHP_ADMIN_URL points at its admin API";

    public function testAHealthyInstallPassesEveryCheckAndExitsZero(): void
    {
        $tester = $this->execute(new RecordedAdminApi()->status());

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame("pass: worker threads\npass: server metrics\npass: Mercure hub\npass: forge\n", $tester->getDisplay());
    }

    public function testWorkerThreadsThatAreAllRestartingFailTheWorkerCheckWithItsFix(): void
    {
        $tester = $this->execute(new RecordedAdminApi()->answering('/frankenphp/threads', RecordedAdminApi::fixture('threads_no_ready_worker.json'))->status());

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertSame(
            "fail: worker threads: no worker thread is ready; fix: check the FrankenPHP log for a worker that fails to boot or keeps restarting\n"
            ."pass: server metrics\npass: Mercure hub\npass: forge\n",
            $tester->getDisplay(),
        );
    }

    public function testAThreadsEndpointThatDoesNotAnswerFailsTheWorkerCheckWithItsFix(): void
    {
        $tester = $this->execute($this->statusReading(threads: new EndpointDown('/frankenphp/threads did not answer')));

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertSame(
            'fail: worker threads: worker threads down (/frankenphp/threads did not answer); fix: '.self::THREADS_FIX."\n"
            ."pass: server metrics\npass: Mercure hub\npass: forge\n",
            $tester->getDisplay(),
        );
    }

    public function testAMetricsEndpointThatAnswersAnErrorFailsTheMetricsCheckWithItsFix(): void
    {
        $tester = $this->execute(new RecordedAdminApi()->answering('/metrics', '', 500)->status());

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertSame(
            "pass: worker threads\n"
            .'fail: server metrics: server metrics down (/metrics answered with status 500); fix: '.self::METRICS_FIX."\n"
            ."pass: Mercure hub\npass: forge\n",
            $tester->getDisplay(),
        );
    }

    public function testAHubThatReportsUnhealthyFailsTheHubCheckWithItsFix(): void
    {
        $tester = $this->execute(new RecordedAdminApi()->answering('/mercure/health/ready', '', 503)->status());

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertSame(
            "pass: worker threads\npass: server metrics\n"
            ."fail: Mercure hub: the hub reports unhealthy; fix: check the FrankenPHP log for the Mercure hub's error, for example a transport file under MERCURE_BOLT_PATH it cannot write\npass: forge\n",
            $tester->getDisplay(),
        );
    }

    public function testAStoppedWebServerFailsEveryCheckWithItsFix(): void
    {
        $tester = $this->execute(new RecordedAdminApi()->unreachable()->status());

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertSame(
            'fail: worker threads: worker threads down (/frankenphp/threads did not answer); fix: '.self::THREADS_FIX."\n"
            .'fail: server metrics: server metrics down (/metrics did not answer); fix: '.self::METRICS_FIX."\n"
            .'fail: Mercure hub: hub down (/mercure/health/ready did not answer); fix: '.self::HUB_DOWN_FIX."\npass: forge\n",
            $tester->getDisplay(),
        );
    }

    public function testAnAdminApiOffLocalhostFailsEveryCheckAsNotRunWithoutARequest(): void
    {
        $api = new RecordedAdminApi();

        $tester = $this->execute($api->status('http://192.0.2.10:2019'));

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        $lines = explode("\n", rtrim($tester->getDisplay(), "\n"));
        self::assertCount(4, $lines);
        foreach (['worker threads', 'server metrics', 'Mercure hub'] as $index => $name) {
            self::assertStringStartsWith('fail: '.$name.': cannot run (FRANKENPHP_ADMIN_URL must be plain http on localhost', $lines[$index]);
            self::assertStringEndsWith('; fix: set FRANKENPHP_ADMIN_URL to plain http on localhost, for example http://localhost:2019', $lines[$index]);
        }
        self::assertSame([], $api->requestedUrls);
    }

    public function testAFixTextWithALineBreakPrintsAsOneLine(): void
    {
        $check = InfrastructureCheck::unreadable(new EndpointDown('x'), "the problem\nin two lines", "first step\nsecond step\r\x1B[31m");
        $line = new ReflectionMethod(DoctorCommand::class, 'line');

        $printed = $line->invoke(new DoctorCommand(self::createStub(ServerStatus::class), $this->forge()), 'worker threads', $check);

        self::assertSame('fail: worker threads: the problem in two lines; fix: first step second step  [31m', $printed);
    }

    public function testAProblemTextWithLineBreaksAndControlCharactersGoesThroughTheSameOneLineHelperAsTheFixText(): void
    {
        $tester = $this->execute($this->statusReading(threads: new EndpointDown("first\nsecond\r\x1B[31mthird\u{2028}fourth\u{85}fifth")));

        self::assertSame(
            'fail: worker threads: worker threads down (first second  [31mthird fourth fifth); fix: '.self::THREADS_FIX."\n"
            ."pass: server metrics\npass: Mercure hub\npass: forge\n",
            $tester->getDisplay(),
        );
    }

    public function testATextThatIsNotUtf8StillPrintsOnOneLine(): void
    {
        $tester = $this->execute($this->statusReading(threads: new EndpointDown("broken \xFF\nbyte")));

        self::assertSame(4, substr_count($tester->getDisplay(), "\n"));
        self::assertStringContainsString('(broken ? byte)', $tester->getDisplay());
    }

    public function testATextThatLooksLikeConsoleMarkupIsPrintedAsItIs(): void
    {
        $tester = $this->execute($this->statusReading(threads: new EndpointDown('<error>red</error>')));

        self::assertStringContainsString('worker threads down (<error>red</error>)', $tester->getDisplay());
    }

    public function testTheDoctorOnlyReadsTheThreeAdminEndpoints(): void
    {
        $api = new RecordedAdminApi();

        $this->execute($api->status());

        self::assertSame(
            ['http://localhost:2019/frankenphp/threads', 'http://localhost:2019/metrics', 'http://localhost:2019/mercure/health/ready'],
            $api->requestedUrls,
        );
    }

    public function testAHalfClearedAppFailsTheForgeCheckWithOneLineAndExitsOne(): void
    {
        $tester = $this->execute(new RecordedAdminApi()->status(), $this->forge(installationId: "678\nsecond"));

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertSame(
            "pass: worker threads\npass: server metrics\npass: Mercure hub\n"
            ."fail: forge: GITHUB_APP_ID is empty while GITHUB_APP_INSTALLATION_ID or GITHUB_APP_PRIVATE_KEY is still set; fix: set GITHUB_APP_ID to the App ID, or empty the installation ID and the private key as well to run without an App\n",
            $tester->getDisplay(),
        );
    }

    public function testABadKeyFailsTheForgeCheckWhileTheOthersPass(): void
    {
        $tester = $this->execute(new RecordedAdminApi()->status(), $this->forge(
            appId: '1',
            installationId: '2',
            privateKey: 'not-a-key',
        ));

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString("fail: forge: the private key cannot sign (it cannot be read or is not an RSA key); fix: set GITHUB_APP_PRIVATE_KEY to the PEM private key of the App\n", $tester->getDisplay());
    }

    private function forge(string $appId = '', string $installationId = '', string $privateKey = ''): ForgeConnectionCheck
    {
        $clock = new MockClock('2026-11-25 10:00:00 UTC');

        return new ForgeConnectionCheck(
            new MockHttpClient(),
            new AppJwt($appId, $privateKey, $clock),
            new ExpectedInstallation(['contents' => 'write'], ['acme/widget']),
            $clock,
            $clock,
            new RateLimitReadings(new ArrayAdapter(clock: $clock), $clock, new LockFactory(new InMemoryStore())),
            new NullLogger(),
            $appId,
            $installationId,
            $privateKey,
        );
    }

    private function statusReading(EndpointDown $threads): ServerStatus
    {
        $status = self::createStub(ServerStatus::class);
        $status->method('read')->willReturn(new ServerReading($threads, new ServerMetrics(0, 0), HubHealth::Healthy));

        return $status;
    }

    private function execute(ServerStatus $status, ?ForgeConnectionCheck $forge = null): CommandTester
    {
        $application = new Application();
        $application->addCommand(new DoctorCommand($status, $forge ?? $this->forge()));
        $tester = new CommandTester($application->find('intakt:doctor'));
        $tester->execute([]);

        return $tester;
    }
}
