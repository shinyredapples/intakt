<?php

declare(strict_types=1);

namespace Intakt\Core\Server\Infrastructure;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

// The dashboard cannot report that it does not answer, so the doctor asks it the way the container's health check does (decision D2 of #3827).
final readonly class WebServerCheck
{
    // The status page, not the board, because a board load may wait up to 15 seconds on the forge check, which the doctor runs on its own line.
    private const string PATH = '/status';
    // No redirects and the health check's 4 seconds, so a hung server fails the line instead of holding the doctor (decision D5).
    private const array OPTIONS = ['max_redirects' => 0, 'timeout' => 4, 'max_duration' => 4];
    private const string DOWN_FIX = 'check that FrankenPHP is running and listens on SERVER_PORT (in the compose stack, docker compose restart intakt starts it again)';
    private const string ERROR_FIX = 'check the FrankenPHP log and var/log for the error the page ends on';

    public function __construct(
        private HttpClientInterface $http,
        #[Autowire('%env(int:SERVER_PORT)%')]
        private int $serverPort,
        #[Autowire('%env(int:DASHBOARD_PORT)%')]
        private int $dashboardPort,
    ) {
    }

    public function run(): CheckVerdict
    {
        try {
            $status = $this->http->request('GET', 'http://localhost:'.$this->serverPort.self::PATH, $this->options())->getStatusCode();
        } catch (ExceptionInterface) {
            // Without the transport's message, because the problem names the piece and the fix says what to do.
            return CheckVerdict::failed('web server down ('.self::PATH.' did not answer on port '.$this->serverPort.')', self::DOWN_FIX);
        }

        return $status >= 200 && $status < 300
            ? CheckVerdict::passed()
            : CheckVerdict::failed('web server answered '.self::PATH.' with status '.$status, self::ERROR_FIX);
    }

    /**
     * @return array<string, mixed>
     */
    private function options(): array
    {
        // Named so, because DashboardHost refuses any other name; past no proxy, because a proxy would ask its own localhost.
        return [...self::OPTIONS, 'headers' => ['Host' => 'localhost:'.$this->dashboardPort], 'no_proxy' => '*'];
    }
}
