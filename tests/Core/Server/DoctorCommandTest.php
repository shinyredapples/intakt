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
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

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
final class DoctorCommandTest extends TestCase
{
    private const string THREADS_FIX = 'check that FrankenPHP is running and that FRANKENPHP_ADMIN_URL points at its admin API';
    private const string METRICS_FIX = "check that the Caddyfile's global options turn on metrics and that FRANKENPHP_ADMIN_URL points at the admin API";
    private const string HUB_DOWN_FIX = "check that FrankenPHP runs with the Caddyfile's mercure block and that FRANKENPHP_ADMIN_URL points at its admin API";

    public function testAHealthyInstallPassesEveryCheckAndExitsZero(): void
    {
        $tester = $this->execute(new RecordedAdminApi()->status());

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame("pass: worker threads\npass: server metrics\npass: Mercure hub\n", $tester->getDisplay());
    }

    public function testWorkerThreadsThatAreAllRestartingFailTheWorkerCheckWithItsFix(): void
    {
        $tester = $this->execute(new RecordedAdminApi()->answering('/frankenphp/threads', RecordedAdminApi::fixture('threads_no_ready_worker.json'))->status());

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertSame(
            "fail: worker threads: no worker thread is ready; fix: check the FrankenPHP log for a worker that fails to boot or keeps restarting\n"
            ."pass: server metrics\npass: Mercure hub\n",
            $tester->getDisplay(),
        );
    }

    public function testAThreadsEndpointThatDoesNotAnswerFailsTheWorkerCheckWithItsFix(): void
    {
        $tester = $this->execute($this->statusReading(threads: new EndpointDown('/frankenphp/threads did not answer')));

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertSame(
            'fail: worker threads: worker threads down (/frankenphp/threads did not answer); fix: '.self::THREADS_FIX."\n"
            ."pass: server metrics\npass: Mercure hub\n",
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
            ."pass: Mercure hub\n",
            $tester->getDisplay(),
        );
    }

    public function testAHubThatReportsUnhealthyFailsTheHubCheckWithItsFix(): void
    {
        $tester = $this->execute(new RecordedAdminApi()->answering('/mercure/health/ready', '', 503)->status());

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertSame(
            "pass: worker threads\npass: server metrics\n"
            ."fail: Mercure hub: the hub reports unhealthy; fix: check the FrankenPHP log for the Mercure hub's error, for example a transport file under MERCURE_BOLT_PATH it cannot write\n",
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
            .'fail: Mercure hub: hub down (/mercure/health/ready did not answer); fix: '.self::HUB_DOWN_FIX."\n",
            $tester->getDisplay(),
        );
    }

    public function testAnAdminApiOffLocalhostFailsEveryCheckAsNotRunWithoutARequest(): void
    {
        $api = new RecordedAdminApi();

        $tester = $this->execute($api->status('http://192.0.2.10:2019'));

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        $lines = explode("\n", rtrim($tester->getDisplay(), "\n"));
        self::assertCount(3, $lines);
        foreach (['worker threads', 'server metrics', 'Mercure hub'] as $index => $name) {
            self::assertStringStartsWith('fail: '.$name.': cannot run (FRANKENPHP_ADMIN_URL must be plain http on localhost', $lines[$index]);
            self::assertStringEndsWith('; fix: set FRANKENPHP_ADMIN_URL to plain http on localhost, for example http://localhost:2019', $lines[$index]);
        }
        self::assertSame([], $api->requestedUrls);
    }

    public function testAProblemTextWithLineBreaksAndControlCharactersStaysOnOneLine(): void
    {
        $tester = $this->execute($this->statusReading(threads: new EndpointDown("first\nsecond\r\x1B[31mthird\u{2028}fourth\u{85}fifth")));

        self::assertSame(
            'fail: worker threads: worker threads down (first second  [31mthird fourth fifth); fix: '.self::THREADS_FIX."\n"
            ."pass: server metrics\npass: Mercure hub\n",
            $tester->getDisplay(),
        );
    }

    public function testATextThatIsNotUtf8StillPrintsOnOneLine(): void
    {
        $tester = $this->execute($this->statusReading(threads: new EndpointDown("broken \xFF\nbyte")));

        self::assertSame(3, substr_count($tester->getDisplay(), "\n"));
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

    private function statusReading(EndpointDown $threads): ServerStatus
    {
        $status = self::createStub(ServerStatus::class);
        $status->method('read')->willReturn(new ServerReading($threads, new ServerMetrics(0, 0), HubHealth::Healthy));

        return $status;
    }

    private function execute(ServerStatus $status): CommandTester
    {
        $application = new Application();
        $application->addCommand(new DoctorCommand($status));
        $tester = new CommandTester($application->find('intakt:doctor'));
        $tester->execute([]);

        return $tester;
    }
}
