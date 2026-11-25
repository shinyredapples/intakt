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
    private const string PORT_FIX = 'set SERVER_PORT and DASHBOARD_PORT in .env.local to port numbers between 1 and 65535';

    // Read as text and checked here, because a port the container cast to a number would stop the whole doctor before its first line (decision D5).
    public function __construct(
        private HttpClientInterface $http,
        #[Autowire('%env(SERVER_PORT)%')]
        private string $serverPort,
        #[Autowire('%env(DASHBOARD_PORT)%')]
        private string $dashboardPort,
    ) {
    }

    public function run(): DoctorCheckVerdict
    {
        $serverPort = self::port($this->serverPort);
        $dashboardPort = self::port($this->dashboardPort);
        if (null === $serverPort || null === $dashboardPort) {
            return DoctorCheckVerdict::failed('cannot run (SERVER_PORT or DASHBOARD_PORT is not a port number)', self::PORT_FIX);
        }
        try {
            $status = $this->http->request('GET', 'http://localhost:'.$serverPort.self::PATH, $this->options($dashboardPort))->getStatusCode();
        } catch (ExceptionInterface) {
            // Without the transport's message, because the problem names the piece and the fix says what to do.
            return DoctorCheckVerdict::failed('web server down ('.self::PATH.' did not answer on port '.$serverPort.')', self::DOWN_FIX);
        }

        return $status >= 200 && $status < 300
            ? DoctorCheckVerdict::passed()
            : DoctorCheckVerdict::failed('web server answered '.self::PATH.' with status '.$status, self::ERROR_FIX);
    }

    /**
     * @return array<string, mixed>
     */
    private function options(int $dashboardPort): array
    {
        // Named so, because DashboardHost refuses any other name; past no proxy, because a proxy would ask its own localhost.
        return [...self::OPTIONS, 'headers' => ['Host' => 'localhost:'.$dashboardPort], 'no_proxy' => '*'];
    }

    private static function port(string $value): ?int
    {
        // Digits only, because the integer filter also takes a sign or surrounding blanks no port carries.
        $port = 1 === preg_match('/^[0-9]{1,5}$/', $value) ? (int) $value : 0;

        return $port >= 1 && $port <= 65535 ? $port : null;
    }
}
