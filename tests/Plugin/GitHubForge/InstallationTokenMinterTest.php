<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubForge;

use DateTimeImmutable;
use Intakt\Plugin\Forge\Contract\ForgeCredentialsRefused;
use Intakt\Plugin\GitHubForge\Contract\InstallationToken;
use Intakt\Plugin\GitHubForge\Contract\InstallationTokenNotMinted;
use Intakt\Plugin\GitHubForge\Infrastructure\AppJwt;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationTokenMinter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Throwable;

#[CoversClass(InstallationTokenMinter::class)]
#[UsesClass(InstallationToken::class)]
#[UsesClass(InstallationTokenNotMinted::class)]
#[UsesClass(ForgeCredentialsRefused::class)]
#[UsesClass(AppJwt::class)]
final class InstallationTokenMinterTest extends TestCase
{
    public function testARecordedAnswerYieldsTheTokenWithItsExpiryInUtc(): void
    {
        $requests = [];
        $minter = $this->minter(
            static function (string $method, string $url, array $options) use (&$requests): ResponseInterface {
                $requests[] = [$method, $url, self::authorization($options)];

                return new MockResponse(self::answer('opaque-value', '2026-11-25T11:00:00+01:00'), ['http_code' => 201]);
            },
        );

        $token = $minter->mint('678');

        self::assertSame('opaque-value', $token->value);
        self::assertEquals(new DateTimeImmutable('2026-11-25T10:00:00Z'), $token->expiresAt);
        self::assertSame('UTC', $token->expiresAt->getTimezone()->getName());
        self::assertSame('POST', $requests[0][0]);
        self::assertSame('https://api.github.com/app/installations/678/access_tokens', $requests[0][1]);
        self::assertStringStartsWith('Authorization: Bearer ', $requests[0][2]);
    }

    #[DataProvider('refusals')]
    public function testARefusedExchangeFailsTheCallWithTheStatus(string $fixture, int $status): void
    {
        $minter = $this->minter(static fn (): ResponseInterface => new MockResponse(self::recorded($fixture), ['http_code' => $status]));

        $this->expectException(InstallationTokenNotMinted::class);
        $this->expectExceptionMessageMatches('/status '.$status.'/');

        (void) $minter->mint('678');
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function refusals(): iterable
    {
        yield 'bad credentials' => ['access_tokens_401.json', 401];
        yield 'installation not found' => ['access_tokens_404.json', 404];
    }

    public function testAnExchangeRefusedWith401CarriesRefusedCredentialsAsItsCause(): void
    {
        $minter = $this->minter(static fn (): ResponseInterface => new MockResponse(self::recorded('access_tokens_401.json'), ['http_code' => 401]));

        try {
            (void) $minter->mint('678');
            self::fail('The exchange was expected to fail.');
        } catch (InstallationTokenNotMinted $notMinted) {
            self::assertInstanceOf(ForgeCredentialsRefused::class, $notMinted->getPrevious());
        }
    }

    public function testAnExchangeRefusedWithAnotherStatusCarriesNoRefusedCredentialsCause(): void
    {
        $minter = $this->minter(static fn (): ResponseInterface => new MockResponse(self::recorded('access_tokens_404.json'), ['http_code' => 404]));

        try {
            (void) $minter->mint('678');
            self::fail('The exchange was expected to fail.');
        } catch (InstallationTokenNotMinted $notMinted) {
            self::assertNull($notMinted->getPrevious());
        }
    }

    public function testATransportErrorFailsTheCall(): void
    {
        $minter = $this->minter(static fn (): ResponseInterface => throw new TransportException('connection reset'));

        $this->expectException(InstallationTokenNotMinted::class);

        (void) $minter->mint('678');
    }

    public function testAnAnswerWithoutATokenFailsTheCall(): void
    {
        $minter = $this->minter(static fn (): ResponseInterface => new MockResponse('{"expires_at":"2026-11-25T11:00:00Z"}', ['http_code' => 201]));

        $this->expectException(InstallationTokenNotMinted::class);

        (void) $minter->mint('678');
    }

    public function testAnAnswerWithAnUnreadableExpiryFailsTheCall(): void
    {
        $minter = $this->minter(static fn (): ResponseInterface => new MockResponse(self::answer('opaque-value', 'tomorrow'), ['http_code' => 201]));

        $this->expectException(InstallationTokenNotMinted::class);

        (void) $minter->mint('678');
    }

    public function testAnAnswerWithAnImpossibleExpiryDateFailsTheCall(): void
    {
        // PHP rolls November 31 over to December 1 and only warns; a credential must not be cached on a guessed expiry.
        $minter = $this->minter(static fn (): ResponseInterface => new MockResponse(self::answer('opaque-value', '2026-11-31T11:00:00Z'), ['http_code' => 201]));

        $this->expectException(InstallationTokenNotMinted::class);

        (void) $minter->mint('678');
    }

    public function testTheInstallationTokenIsNotAnArgumentInTheTraceOfAnInvalidAnswer(): void
    {
        $minter = $this->minter(static fn (): ResponseInterface => new MockResponse(self::answer('minted-value-that-must-stay-secret', 'tomorrow'), ['http_code' => 201]));
        $ignoreArguments = ini_set('zend.exception_ignore_args', '0');

        try {
            (void) $minter->mint('678');
            self::fail('An unreadable expiry must fail.');
        } catch (InstallationTokenNotMinted $failure) {
            $arguments = $this->scalarArguments($failure);
        } finally {
            ini_set('zend.exception_ignore_args', (string) $ignoreArguments);
        }

        self::assertNotSame([], $arguments);
        self::assertNotContains('minted-value-that-must-stay-secret', $arguments);
    }

    public function testNeitherTheKeyNorTheJwtReachesTheLogsOrTheFailure(): void
    {
        $key = AppKey::generate();
        $sent = [];
        $logger = new RecordingLogger();
        $minter = $this->minter(
            static function (string $method, string $url, array $options) use (&$sent): ResponseInterface {
                $sent[] = self::authorization($options);

                return new MockResponse(self::recorded('access_tokens_401.json'), ['http_code' => 401]);
            },
            $key,
            $logger,
        );

        try {
            (void) $minter->mint('678');
            self::fail('A refused exchange must fail.');
        } catch (InstallationTokenNotMinted $failure) {
            $everything = implode("\n", $logger->lines).$failure->getMessage().$failure->getTraceAsString();
        }

        self::assertNotSame([], $logger->lines);
        self::assertStringNotContainsString($key->body(), $everything);
        self::assertStringNotContainsString(substr($sent[0], strlen('Authorization: Bearer ')), $everything);
    }

    public function testTheJwtIsNotAnArgumentInTheTraceOfAFailedExchange(): void
    {
        $sent = [];
        $minter = $this->minter(
            static function (string $method, string $url, array $options) use (&$sent): ResponseInterface {
                $sent[] = self::authorization($options);

                return new MockResponse(self::recorded('access_tokens_401.json'), ['http_code' => 401]);
            },
        );
        $ignoreArguments = ini_set('zend.exception_ignore_args', '0');

        try {
            (void) $minter->mint('678');
            self::fail('A refused exchange must fail.');
        } catch (InstallationTokenNotMinted $failure) {
            $arguments = $this->scalarArguments($failure);
        } finally {
            ini_set('zend.exception_ignore_args', (string) $ignoreArguments);
        }

        self::assertNotSame([], $arguments);
        self::assertNotContains(substr($sent[0], strlen('Authorization: Bearer ')), $arguments);
    }

    public function testTheJwtIsNotAnArgumentAnywhereInTheChainOfATransportFailure(): void
    {
        $sent = [];
        $minter = $this->minter(
            static function (string $method, string $url, array $options) use (&$sent): ResponseInterface {
                $sent[] = self::authorization($options);

                throw new TransportException('connection reset');
            },
        );
        $ignoreArguments = ini_set('zend.exception_ignore_args', '0');

        try {
            (void) $minter->mint('678');
            self::fail('A transport error must fail.');
        } catch (InstallationTokenNotMinted $failure) {
            $arguments = $this->scalarArguments($failure);
        } finally {
            ini_set('zend.exception_ignore_args', (string) $ignoreArguments);
        }

        $jwt = substr($sent[0], strlen('Authorization: Bearer '));
        self::assertNotSame([], $arguments);
        self::assertSame([], array_filter($arguments, static fn (mixed $argument): bool => str_contains((string) $argument, $jwt)));
    }

    /**
     * @return list<scalar>
     */
    private function scalarArguments(Throwable $failure): array
    {
        // Only scalars and arrays, because printing the object arguments would walk PHPUnit's whole object graph.
        $arguments = [];
        for ($link = $failure; $link instanceof Throwable; $link = $link->getPrevious()) {
            foreach ($link->getTrace() as $frame) {
                $arguments = [...$arguments, ...self::scalarsIn($frame['args'] ?? [])];
            }
        }

        return $arguments;
    }

    /**
     * @param array<mixed> $values
     *
     * @return list<scalar>
     */
    private static function scalarsIn(array $values): array
    {
        $scalars = [];
        foreach ($values as $value) {
            if (is_scalar($value)) {
                $scalars[] = $value;
            }
            if (is_array($value)) {
                $scalars = [...$scalars, ...self::scalarsIn($value)];
            }
        }

        return $scalars;
    }

    /**
     * @param array<mixed> $options
     */
    private static function authorization(array $options): string
    {
        $headers = $options['normalized_headers'];
        self::assertIsArray($headers);

        $authorization = (array) ($headers['authorization'] ?? []);

        return is_string($authorization[0] ?? null) ? $authorization[0] : '';
    }

    private static function answer(string $token, string $expiresAt): string
    {
        return json_encode(['token' => $token, 'expires_at' => $expiresAt], JSON_THROW_ON_ERROR);
    }

    private function minter(callable $respond, ?AppKey $key = null, ?LoggerInterface $logger = null): InstallationTokenMinter
    {
        $jwt = new AppJwt('12345', ($key ?? AppKey::generate())->private, new MockClock('2026-11-25 10:00:00 UTC'));

        return new InstallationTokenMinter($jwt, new MockHttpClient($respond), $logger ?? self::createStub(LoggerInterface::class));
    }

    private static function recorded(string $fixture): string
    {
        return (string) file_get_contents(__DIR__.'/Fixtures/'.$fixture);
    }
}
