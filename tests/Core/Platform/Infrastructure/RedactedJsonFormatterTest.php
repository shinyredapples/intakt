<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Platform\Infrastructure;

use Intakt\Core\Platform\Contract\Redaction;
use Intakt\Core\Platform\Infrastructure\RedactedJsonFormatter;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Clock\MockClock;

#[CoversClass(RedactedJsonFormatter::class)]
#[UsesClass(Redaction::class)]
final class RedactedJsonFormatterTest extends TestCase
{
    private const string CREDENTIAL = 'INTAKT_TEST_HELD_CREDENTIAL';

    protected function tearDown(): void
    {
        unset($_SERVER[self::CREDENTIAL]);
    }

    public function testALoggedExceptionCarriesItsClassMessageAndTrace(): void
    {
        $line = $this->format('error', 'It failed.', ['reviewer' => 'r1', 'exception' => new RuntimeException('boom')]);

        $failure = $this->loggedFailure($line);
        self::assertSame(RuntimeException::class, $failure['class']);
        self::assertSame('boom', $failure['message']);
        self::assertStringContainsString(__FUNCTION__, $failure['trace']);
    }

    public function testAPreviousExceptionIsWrittenWithItsOwnClass(): void
    {
        $line = $this->format('error', 'It failed.', ['exception' => new RuntimeException('outer', 0, new LogicException('inner'))]);

        self::assertSame(LogicException::class, $this->loggedFailure($line)['previous']['class'] ?? null);
    }

    public function testEachRecordIsOneJsonLineHoldingItsContextItem(): void
    {
        $line = $this->format('warning', 'Round {round} stalled.', ['round' => 'R7']);

        self::assertStringNotContainsString("\n", $line);
        self::assertSame(
            ['time' => '2026-11-03T10:00:00.000+00:00', 'level' => 'warning', 'message' => 'Round R7 stalled.', 'context' => ['round' => 'R7']],
            $this->decoded($line),
        );
    }

    public function testAHeldCredentialInTheMessageIsWrittenRedacted(): void
    {
        $line = $this->format('error', 'Call with held-secret-1 failed.', []);

        self::assertStringNotContainsString('held-secret-1', $line);
        self::assertStringContainsString('[redacted]', $line);
    }

    public function testAHeldCredentialInsideAnExceptionMessageIsWrittenRedacted(): void
    {
        $line = $this->format('error', 'It failed.', ['exception' => new RuntimeException('rejected key held-secret-1')]);

        self::assertStringNotContainsString('held-secret-1', $line);
    }

    public function testAHeldCredentialWithCharactersJsonEscapesIsStillRedactedAfterEncoding(): void
    {
        $line = $this->format('error', 'Key pa"ss/wo\\rd failed.', [], 'pa"ss/wo\\rd');

        self::assertStringNotContainsString('wo', $line);
        self::assertStringContainsString('[redacted]', $line);
    }

    public function testAnUnsetCredentialRedactsNothing(): void
    {
        $line = $this->format('error', 'Plain text.', [], null);

        self::assertSame('Plain text.', $this->decoded($line)['message'] ?? null);
    }

    public function testAUrlWithUserinfoIsWrittenRedacted(): void
    {
        $line = $this->format('error', 'Fetch failed.', ['url' => 'https://user:pass@example.test/path']);

        self::assertStringNotContainsString('user:pass', $line);
        self::assertStringContainsString('example.test', $line);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function headerSecrets(): iterable
    {
        yield 'plain text' => ['Authorization: Bearer abc123xyz'];
        yield 'shell quoted' => ['curl -H \'Authorization: token abc123xyz\' https://example.com'];
        yield 'quoted' => ['{"Authorization": "Basic abc123xyz"}'];
        yield 'deep json' => ['{"a":{"b":[{"headers":{"Authorization":"Bearer abc123xyz"}}]}}'];
        yield 'escaped json' => ['{"msg":"{\"Authorization\":\"Bearer abc123xyz\"}"}'];
        yield 'escaped quote in json' => ['{"Authorization":"abc\"tail123","next":"ok"}'];
        yield 'cookie' => ["Cookie: session=abc123xyz\nnext"];
        yield 'print_r scalar' => ['[Authorization] => Bearer abc123xyz'];
    }

    #[DataProvider('headerSecrets')]
    public function testAnAuthorizationHeaderInTheContextIsWrittenRedacted(string $header): void
    {
        $line = $this->format('error', 'Request failed.', ['headers' => $header]);

        self::assertStringNotContainsString('abc123xyz', $line);
        self::assertStringNotContainsString('tail123', $line);
        self::assertStringContainsString('[redacted]', $line);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function format(string $level, string $message, array $context, ?string $credential = 'held-secret-1'): string
    {
        $_SERVER[self::CREDENTIAL] = $credential;
        $formatter = new RedactedJsonFormatter(new Redaction(), new MockClock('2026-11-03 10:00:00 UTC'), [self::CREDENTIAL, 'INTAKT_TEST_UNSET_CREDENTIAL']);

        return $formatter($level, $message, $context);
    }

    /**
     * @return array{class: string, message: string, trace: string, previous?: array{class: string}}
     */
    private function loggedFailure(string $line): array
    {
        $context = $this->decoded($line)['context'] ?? [];
        self::assertIsArray($context);
        $failure = $context['exception'] ?? null;
        self::assertIsArray($failure);

        /* @var array{class: string, message: string, trace: string, previous?: array{class: string}} $failure */
        return $failure;
    }

    /**
     * @return array<string, mixed>
     */
    private function decoded(string $line): array
    {
        return json_decode($line, true, 512, JSON_THROW_ON_ERROR);
    }
}
