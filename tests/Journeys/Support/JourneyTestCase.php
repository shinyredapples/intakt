<?php

declare(strict_types=1);

namespace Intakt\Tests\Journeys\Support;

use PHPUnit\Framework\TestCase;
use Symfony\Component\BrowserKit\HttpBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Process\Process;

// Journeys run only under `bin/check --journeys`, which starts bin/instance and so sets the address below.
abstract class JourneyTestCase extends TestCase
{
    private const string ADDRESS_VARIABLE = 'INTAKT_INSTANCE_URL';
    private const string ROOT = __DIR__.'/../../..';
    // The instance runs in the test environment, so it reads the same file.
    private const string TEST_SETTINGS = '.env.test';
    private const int CONSOLE_SECONDS = 60;

    private JourneySteps $steps;

    private HttpBrowser $browser;

    abstract protected function journey(): string;

    final protected function setUp(): void
    {
        $this->steps = new JourneySteps($this->journey());
        $this->browser = new HttpBrowser(HttpClient::create());
    }

    /**
     * @template Result
     *
     * @param callable(): Result $action
     *
     * @return Result
     */
    final protected function step(string $name, callable $action): mixed
    {
        return $this->steps->run($name, $action);
    }

    final protected function visit(string $path): Crawler
    {
        $crawler = $this->browser->request('GET', $this->address().$path, server: ['HTTP_HOST' => 'localhost:'.$this->dashboardPort()]);

        self::assertSame(200, $this->browser->getResponse()->getStatusCode(), 'GET '.$path.' did not answer 200');

        return $crawler;
    }

    /**
     * @param list<string>         $arguments
     * @param array<string,string> $environment
     */
    final protected function console(array $arguments, array $environment = []): string
    {
        $process = new Process([PHP_BINARY, self::ROOT.'/bin/console', '--no-interaction', ...$arguments], self::ROOT, $environment, null, self::CONSOLE_SECONDS);
        $process->run();

        self::assertTrue($process->isSuccessful(), 'bin/console '.implode(' ', $arguments).' failed: '.trim($process->getErrorOutput().$process->getOutput()));

        return $process->getOutput();
    }

    // Because the dashboard refuses any other host name or port than its own, and the instance listens on a free port.
    private function dashboardPort(): string
    {
        $port = new Dotenv()->parse((string) file_get_contents(self::ROOT.'/'.self::TEST_SETTINGS))['DASHBOARD_PORT'] ?? null;

        return is_string($port) && '' !== $port ? $port : self::fail('DASHBOARD_PORT is not set in '.self::TEST_SETTINGS);
    }

    private function address(): string
    {
        $address = getenv(self::ADDRESS_VARIABLE);

        if (!is_string($address) || '' === $address) {
            self::fail(self::ADDRESS_VARIABLE.' is not set: run the journeys with bin/check --journeys');
        }

        return $address;
    }
}
