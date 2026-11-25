<?php

declare(strict_types=1);

namespace Intakt\Core\Server\UseCase\Doctor;

use Intakt\Core\Server\Contract\AdminApiNotOnLocalhost;
use Intakt\Core\Server\Contract\EndpointDown;
use Intakt\Core\Server\Contract\HubHealth;
use Intakt\Core\Server\Contract\InfrastructureCheck;
use Intakt\Core\Server\Contract\InfrastructureChecks;
use Intakt\Core\Server\Contract\ServerMetrics;
use Intakt\Core\Server\Contract\ServerStatus;
use Intakt\Core\Server\Contract\WorkerThreads;
use Intakt\Core\Server\Infrastructure\ConsumerProcessesCheck;
use Intakt\Core\Server\Infrastructure\WebServerCheck;
use Intakt\Plugin\GitHubForge\Contract\ForgeConnection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;

// A console door because the owner needs it when the dashboard does not start; it only judges a reading, so it changes nothing and calls no model (decisions D1 and D3 of #3826).
#[AsCommand(name: 'intakt:doctor', description: 'Run the infrastructure health checks and print one pass or fail line per check, with what to do for a failure')]
final readonly class DoctorCommand
{
    private const string WEB_SERVER = 'web server';
    private const string THREADS = 'worker threads';
    private const string METRICS = 'server metrics';
    private const string HUB = 'Mercure hub';
    private const string WORKERS = 'workers';
    private const string FORGE = 'forge';
    private const string REFUSED_FIX = 'set FRANKENPHP_ADMIN_URL to plain http on localhost, for example http://localhost:2019';

    public function __construct(
        private ServerStatus $status,
        private ForgeConnection $forge,
        private WebServerCheck $webServer,
        private ConsumerProcessesCheck $consumers,
    ) {
    }

    public function __invoke(OutputInterface $output): int
    {
        $webServer = $this->webServer->run();
        $checks = $this->checks();
        $consumers = $this->consumers->run();
        $forge = $this->forge->run();
        $lines = [
            $this->render(self::WEB_SERVER, $webServer->problem, $webServer->fix),
            ...array_map($this->line(...), array_keys($checks), $checks),
            $this->render(self::WORKERS, $consumers->problem, $consumers->fix),
            $this->render(self::FORGE, $forge->problem, $forge->fix),
        ];
        foreach ($lines as $line) {
            // Raw, because a reason is outside text and must not be read as console markup.
            $output->writeln($line, OutputInterface::OUTPUT_RAW);
        }

        return $webServer->passes() && $consumers->passes() && $forge->passes() && array_all($checks, static fn (InfrastructureCheck $check): bool => $check->passes())
            ? Command::SUCCESS
            : Command::FAILURE;
    }

    /**
     * @return array<string, InfrastructureCheck<WorkerThreads|ServerMetrics|HubHealth>>
     */
    private function checks(): array
    {
        try {
            $checks = InfrastructureChecks::of($this->status->read());
        } catch (AdminApiNotOnLocalhost $refused) {
            $cannotRun = InfrastructureCheck::unreadable(new EndpointDown($refused->getMessage()), 'cannot run ('.$refused->getMessage().')', self::REFUSED_FIX);

            return array_fill_keys([self::THREADS, self::METRICS, self::HUB], $cannotRun);
        }

        return [self::THREADS => $checks->threads, self::METRICS => $checks->metrics, self::HUB => $checks->hub];
    }

    /**
     * @param InfrastructureCheck<WorkerThreads|ServerMetrics|HubHealth> $check
     */
    private function line(string $name, InfrastructureCheck $check): string
    {
        return $this->render($name, $check->problem, $check->fix);
    }

    private function render(string $name, ?string $problem, ?string $fix): string
    {
        $line = null === $problem
            ? 'pass: '.$name
            : sprintf('fail: %s: %s; fix: %s', $name, $problem, $fix);

        return $this->oneLine($line);
    }

    // Decision D4 of #3826 promises one line per check, so a line break or terminal escape in any part, also in outside text, becomes a space here and nowhere else.
    private function oneLine(string $text): string
    {
        return preg_replace('/[\p{Cc}\p{Zl}\p{Zp}]/u', ' ', mb_scrub($text, 'UTF-8')) ?? '';
    }
}
