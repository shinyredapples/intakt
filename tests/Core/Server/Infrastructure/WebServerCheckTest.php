<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Server\Infrastructure;

use Intakt\Core\Server\Infrastructure\DoctorCheckVerdict;
use Intakt\Core\Server\Infrastructure\WebServerCheck;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TimeoutException;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

#[CoversClass(WebServerCheck::class)]
#[CoversClass(DoctorCheckVerdict::class)]
final class WebServerCheckTest extends TestCase
{
    private const string DOWN_FIX = 'check that FrankenPHP is running and listens on SERVER_PORT (in the compose stack, docker compose restart intakt starts it again)';
    private const string PORT_FIX = 'set SERVER_PORT and DASHBOARD_PORT in .env.local to port numbers between 1 and 65535';

    public function testAWebServerThatAnswers200Passes(): void
    {
        $verdict = $this->check(new MockResponse('{}'));

        self::assertTrue($verdict->passes());
    }

    public function testTheCheckAsksTheStatusPageOnTheServerPortNamedByTheDashboardHost(): void
    {
        $asked = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$asked): ResponseInterface {
            $asked = [$method, $url, $options['headers'], $options['timeout'], $options['max_duration'], $options['max_redirects']];

            return new MockResponse('{}');
        });

        new WebServerCheck($http, '7781', '7780')->run();

        self::assertSame(['GET', 'http://localhost:7781/status', ['Host: localhost:7780', 'Accept: */*'], 4.0, 4.0, 0], $asked);
    }

    public function testAWebServerThatDoesNotAnswerFailsWithItsFixText(): void
    {
        $verdict = $this->check(new MockResponse('', ['error' => 'Failed to connect to localhost port 7781']));

        self::assertSame('web server down (/status did not answer on port 7781)', $verdict->problem);
        self::assertSame(self::DOWN_FIX, $verdict->fix);
    }

    public function testAWebServerAnswering5xxFailsWithItsFixText(): void
    {
        $verdict = $this->check(new MockResponse('', ['http_code' => 502]));

        self::assertSame('web server answered /status with status 502', $verdict->problem);
        self::assertSame('check the FrankenPHP log and var/log for the error the page ends on', $verdict->fix);
    }

    public function testARedirectIsNotFollowedAndFails(): void
    {
        $verdict = $this->check(new MockResponse('', ['http_code' => 302, 'response_headers' => ['Location' => 'http://example.com/']]));

        self::assertSame('web server answered /status with status 302', $verdict->problem);
    }

    public function testARequestThatTimesOutFailsInsteadOfHanging(): void
    {
        $http = new MockHttpClient(static fn (): never => throw new TimeoutException('Idle timeout reached for "http://localhost:7781/status".'));

        $verdict = new WebServerCheck($http, '7781', '7781')->run();

        self::assertSame('web server down (/status did not answer on port 7781)', $verdict->problem);
    }

    public function testATransportErrorMessageIsNotShown(): void
    {
        $http = new MockHttpClient(static fn (): never => throw new TransportException("secret\nmessage"));

        $verdict = new WebServerCheck($http, '7781', '7781')->run();

        self::assertStringNotContainsString('secret', (string) $verdict->problem);
    }

    public function testANonNumericServerPortFailsAsCannotRunWithItsFixAndSendsNoRequest(): void
    {
        $http = new MockHttpClient(static fn (): never => throw new LogicException('no request expected'));

        $verdict = new WebServerCheck($http, 'invalid', '7781')->run();

        self::assertSame('cannot run (SERVER_PORT or DASHBOARD_PORT is not a port number)', $verdict->problem);
        self::assertSame(self::PORT_FIX, $verdict->fix);
    }

    public function testANonNumericDashboardPortFailsAsCannotRun(): void
    {
        $verdict = new WebServerCheck(new MockHttpClient(new MockResponse('{}')), '7781', '77a1')->run();

        self::assertSame(self::PORT_FIX, $verdict->fix);
    }

    #[DataProvider('portsOutOfRange')]
    public function testAPortOutsideOneTo65535FailsAsCannotRun(string $port): void
    {
        $verdict = new WebServerCheck(new MockHttpClient(new MockResponse('{}')), $port, '7781')->run();

        self::assertSame(self::PORT_FIX, $verdict->fix);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function portsOutOfRange(): iterable
    {
        yield 'zero' => ['0'];
        yield 'one above the top' => ['65536'];
        yield 'negative' => ['-1'];
        yield 'blank around it' => [' 7781'];
        yield 'empty' => [''];
    }

    public function testThePortsAtTheEdgesOfTheRangeAreAsked(): void
    {
        $asked = [];
        $http = new MockHttpClient(static function (string $method, string $url) use (&$asked): ResponseInterface {
            $asked[] = $url;

            return new MockResponse('{}');
        });

        new WebServerCheck($http, '1', '65535')->run();
        new WebServerCheck($http, '65535', '1')->run();

        self::assertSame(['http://localhost:1/status', 'http://localhost:65535/status'], $asked);
    }

    private function check(MockResponse $response): DoctorCheckVerdict
    {
        return new WebServerCheck(new MockHttpClient($response), '7781', '7781')->run();
    }
}
