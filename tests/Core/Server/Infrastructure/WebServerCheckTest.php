<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Server\Infrastructure;

use Intakt\Core\Server\Infrastructure\DoctorCheckVerdict;
use Intakt\Core\Server\Infrastructure\WebServerCheck;
use PHPUnit\Framework\Attributes\CoversClass;
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

        new WebServerCheck($http, 7781, 7780)->run();

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

        $verdict = new WebServerCheck($http, 7781, 7781)->run();

        self::assertSame('web server down (/status did not answer on port 7781)', $verdict->problem);
    }

    public function testATransportErrorMessageIsNotShown(): void
    {
        $http = new MockHttpClient(static fn (): never => throw new TransportException("secret\nmessage"));

        $verdict = new WebServerCheck($http, 7781, 7781)->run();

        self::assertStringNotContainsString('secret', (string) $verdict->problem);
    }

    private function check(MockResponse $response): DoctorCheckVerdict
    {
        return new WebServerCheck(new MockHttpClient($response), 7781, 7781)->run();
    }
}
