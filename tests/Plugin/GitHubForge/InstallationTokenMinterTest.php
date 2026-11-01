<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubForge;

use DateTimeImmutable;
use Intakt\Plugin\GitHubForge\Contract\InstallationToken;
use Intakt\Plugin\GitHubForge\Contract\InstallationTokenNotMinted;
use Intakt\Plugin\GitHubForge\Infrastructure\AppJwt;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationTokenMinter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Stringable;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

#[CoversClass(InstallationTokenMinter::class)]
#[UsesClass(InstallationToken::class)]
#[UsesClass(InstallationTokenNotMinted::class)]
#[UsesClass(AppJwt::class)]
final class InstallationTokenMinterTest extends TestCase
{
    public function testARecordedAnswerYieldsTheTokenWithItsExpiryInUtc(): void
    {
        $requests = [];
        $minter = $this->minter(
            static function (string $method, string $url, array $options) use (&$requests): ResponseInterface {
                $requests[] = [$method, $url, $options['normalized_headers']['authorization'][0] ?? ''];

                return new MockResponse(self::answer('opaque-value', '2026-11-25T11:00:00+01:00'), ['http_code' => 201]);
            },
        );

        $token = $minter->mint();

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
        $this->expectExceptionMessage((string) $status);

        $minter->mint();
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function refusals(): iterable
    {
        yield 'bad credentials' => ['access_tokens_401.json', 401];
        yield 'installation not found' => ['access_tokens_404.json', 404];
    }

    public function testATransportErrorFailsTheCall(): void
    {
        $minter = $this->minter(static fn (): ResponseInterface => throw new TransportException('connection reset'));

        $this->expectException(InstallationTokenNotMinted::class);

        $minter->mint();
    }

    public function testAnAnswerWithoutATokenFailsTheCall(): void
    {
        $minter = $this->minter(static fn (): ResponseInterface => new MockResponse('{"expires_at":"2026-11-25T11:00:00Z"}', ['http_code' => 201]));

        $this->expectException(InstallationTokenNotMinted::class);

        $minter->mint();
    }

    public function testAnAnswerWithAnUnreadableExpiryFailsTheCall(): void
    {
        $minter = $this->minter(static fn (): ResponseInterface => new MockResponse(self::answer('opaque-value', 'tomorrow'), ['http_code' => 201]));

        $this->expectException(InstallationTokenNotMinted::class);

        $minter->mint();
    }

    public function testNeitherTheKeyNorTheJwtReachesTheLogsOrTheFailure(): void
    {
        $key = AppKey::generate();
        $sent = [];
        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $lines = [];

            public function log(mixed $level, string|Stringable $message, array $context = []): void
            {
                $this->lines[] = $message.json_encode($context);
            }
        };
        $minter = $this->minter(
            static function (string $method, string $url, array $options) use (&$sent): ResponseInterface {
                $sent[] = $options['normalized_headers']['authorization'][0];

                return new MockResponse(self::recorded('access_tokens_401.json'), ['http_code' => 401]);
            },
            $key,
            $logger,
        );

        try {
            $minter->mint();
            self::fail('A refused exchange must fail.');
        } catch (InstallationTokenNotMinted $failure) {
            $everything = implode("\n", $logger->lines).$failure->getMessage().$failure->getTraceAsString();
        }

        self::assertNotSame([], $logger->lines);
        self::assertStringNotContainsString($key->body(), $everything);
        self::assertStringNotContainsString(substr($sent[0], strlen('Authorization: Bearer ')), $everything);
    }

    private function minter(callable $respond, ?AppKey $key = null, ?AbstractLogger $logger = null): InstallationTokenMinter
    {
        $jwt = new AppJwt('12345', ($key ?? AppKey::generate())->private, new MockClock(new DateTimeImmutable('2026-11-25 10:00:00 UTC')));

        return new InstallationTokenMinter('678', $jwt, new MockHttpClient($respond), $logger ?? $this->createStub(AbstractLogger::class));
    }

    private static function answer(string $token, string $expiresAt): string
    {
        return json_encode(['token' => $token, 'expires_at' => $expiresAt], JSON_THROW_ON_ERROR);
    }

    private static function recorded(string $fixture): string
    {
        return file_get_contents(__DIR__.'/Fixtures/'.$fixture);
    }
}
