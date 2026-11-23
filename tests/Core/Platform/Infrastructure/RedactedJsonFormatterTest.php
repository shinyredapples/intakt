<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Platform\Infrastructure;

use Intakt\Core\Platform\Contract\Redaction;
use Intakt\Core\Platform\Infrastructure\HeldCredentials;
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
#[UsesClass(HeldCredentials::class)]
final class RedactedJsonFormatterTest extends TestCase
{
    private const string CREDENTIAL = 'INTAKT_TEST_HELD_CREDENTIAL';

    public function testALoggedExceptionCarriesItsClassMessageAndTrace(): void
    {
        $line = $this->format('error', 'It failed.', ['reviewer' => 'r1', 'exception' => new RuntimeException('boom')]);

        $failure = $this->loggedFailure($line);
        self::assertSame(RuntimeException::class, $failure['class']);
        self::assertSame('boom', $failure['message']);
        self::assertStringContainsString(__FUNCTION__, $failure['trace']);
    }

    // The image copies no php.ini, so production keeps the engine defaults: arguments in traces, strings cut to 15 characters when rendered.
    public function testACredentialPassedAsATraceArgumentIsNotWrittenEvenCutShort(): void
    {
        $_SERVER[self::CREDENTIAL] = 'trace-canary-1234567890abcdef';
        $ignoreArguments = ini_set('zend.exception_ignore_args', '0');
        $cut = ini_set('zend.exception_string_param_max_len', '15');

        try {
            $line = $this->format('error', 'It failed.', ['exception' => $this->failedCallWith('trace-canary-1234567890abcdef')]);
        } finally {
            ini_set('zend.exception_ignore_args', (string) $ignoreArguments);
            ini_set('zend.exception_string_param_max_len', (string) $cut);
        }

        self::assertStringNotContainsString('trace-canary', $line);
        self::assertStringContainsString('failedCallWith()', $this->loggedFailure($line)['trace']);
    }

    public function testAPreviousExceptionsTraceHoldsNoArgumentValueEither(): void
    {
        $_SERVER[self::CREDENTIAL] = 'trace-canary-1234567890abcdef';
        $ignoreArguments = ini_set('zend.exception_ignore_args', '0');
        $cut = ini_set('zend.exception_string_param_max_len', '15');

        try {
            $previous = $this->failedCallWith('trace-canary-1234567890abcdef');
            $line = $this->format('error', 'It failed.', ['exception' => new RuntimeException('outer', 0, $previous)]);
        } finally {
            ini_set('zend.exception_ignore_args', (string) $ignoreArguments);
            ini_set('zend.exception_string_param_max_len', (string) $cut);
        }

        self::assertStringNotContainsString('trace-canary', $line);
        self::assertSame(RuntimeException::class, $this->loggedFailure($line)['previous']);
    }

    public function testAPreviousExceptionIsWrittenWithItsOwnClass(): void
    {
        $line = $this->format('error', 'It failed.', ['exception' => new RuntimeException('outer', 0, new LogicException('inner'))]);

        self::assertSame(LogicException::class, $this->loggedFailure($line)['previous']);
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
        $_SERVER[self::CREDENTIAL] = 'pa"ss/wo\\rd';

        $line = $this->format('error', 'Key pa"ss/wo\\rd failed.', []);

        self::assertStringNotContainsString('wo', $line);
        self::assertStringContainsString('[redacted]', $line);
    }

    public function testAnUnsetCredentialRedactsNothing(): void
    {
        unset($_SERVER[self::CREDENTIAL]);

        $line = $this->format('error', 'Plain text.', []);

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

    public function testAnAccountKeyWithItsNameAppendedIsWrittenRedacted(): void
    {
        $_SERVER[self::CREDENTIAL.'_TEAM'] = 'team-key-0123456789abcdef';

        $line = $this->format('error', 'Account key {k} rejected', ['k' => 'team-key-0123456789abcdef']);

        self::assertStringNotContainsString('team-key-0123', $line);
    }

    public function testAnAuthorizationHeaderListInTheContextIsRedactedAndTheLineStaysJson(): void
    {
        $line = $this->format('error', 'Request failed.', ['headers' => ['Authorization' => ['Bearer abc123xyz']], 'reviewer' => 'r1']);

        self::assertSame(['headers' => ['Authorization' => '[redacted]'], 'reviewer' => 'r1'], $this->decoded($line)['context'] ?? null);
    }

    public function testAnAuthorizationHeaderValueInAContextMapIsWrittenRedacted(): void
    {
        $line = $this->format('error', 'Request failed.', ['headers' => ['authorization' => 'Basic abc123xyz']]);

        self::assertSame(['headers' => ['authorization' => '[redacted]']], $this->decoded($line)['context'] ?? null);
    }

    public function testAHeaderValueInterpolatedIntoTheMessageIsWrittenRedacted(): void
    {
        $line = $this->format('warning', 'Rejected header {Authorization}', ['Authorization' => 'Basic abc123xyz']);

        self::assertSame('Rejected header [redacted]', $this->decoded($line)['message'] ?? null);
    }

    public function testAPlaceholderThatNamesNoHeaderIsInterpolated(): void
    {
        $line = $this->format('warning', 'Review by {author} failed.', ['author' => 'octocat']);

        self::assertSame('Review by octocat failed.', $this->decoded($line)['message'] ?? null);
    }

    public function testAContextKeyThatNamesNoHeaderKeepsItsValue(): void
    {
        $line = $this->format('error', 'Review failed.', ['author' => 'octocat']);

        self::assertSame(['author' => 'octocat'], $this->decoded($line)['context'] ?? null);
    }

    public function testTwoContextKeysThatRedactToOneNameBothKeepTheirValues(): void
    {
        $line = $this->format('error', 'Review failed.', ['held-secret-1' => 'first', '[redacted]' => 'second']);

        self::assertStringNotContainsString('held-secret-1', $line);
        self::assertSame(['[redacted]' => 'first', '[redacted] 2' => 'second'], $this->decoded($line)['context'] ?? null);
    }

    public function testAContextKeyThatRedactsToAFreeNameKeepsThatName(): void
    {
        $line = $this->format('error', 'Review failed.', ['held-secret-1' => 'first', 'author' => 'octocat']);

        self::assertSame(['[redacted]' => 'first', 'author' => 'octocat'], $this->decoded($line)['context'] ?? null);
    }

    public function testAnUnterminatedPrivateKeyInAFailureKeepsItsClassAndTrace(): void
    {
        $line = $this->format('error', 'It failed.', ['exception' => new RuntimeException("Bad key -----BEGIN RSA PRIVATE KEY-----\nMIIEabc")]);

        $failure = $this->loggedFailure($line);
        self::assertSame('Bad key [redacted]', $failure['message']);
        self::assertSame(RuntimeException::class, $failure['class']);
        self::assertStringContainsString(__FUNCTION__, $failure['trace']);
    }

    protected function tearDown(): void
    {
        unset($_SERVER[self::CREDENTIAL], $_SERVER[self::CREDENTIAL.'_TEAM']);
    }

    private function failedCallWith(string $credential): RuntimeException
    {
        return new RuntimeException(sprintf('A call with a %d-character key failed.', strlen($credential)));
    }

    /**
     * @param array<string, mixed> $context
     */
    private function format(string $level, string $message, array $context): string
    {
        $_SERVER[self::CREDENTIAL] ??= 'held-secret-1';
        $formatter = new RedactedJsonFormatter(new Redaction(), new MockClock('2026-11-03 10:00:00 UTC'), new HeldCredentials([self::CREDENTIAL, 'INTAKT_TEST_UNSET_CREDENTIAL']));

        return $formatter($level, $message, $context);
    }

    /**
     * @return array{class: string, message: string, trace: string, previous: string|null}
     */
    private function loggedFailure(string $line): array
    {
        $context = $this->decoded($line)['context'] ?? [];
        self::assertIsArray($context);
        $failure = $context['exception'] ?? null;
        self::assertIsArray($failure);
        $previous = $failure['previous'] ?? null;

        return [
            'class' => $this->textAt($failure, 'class'),
            'message' => $this->textAt($failure, 'message'),
            'trace' => $this->textAt($failure, 'trace'),
            'previous' => is_array($previous) ? $this->textAt($previous, 'class') : null,
        ];
    }

    /**
     * @param array<array-key, mixed> $map
     */
    private function textAt(array $map, string $key): string
    {
        self::assertIsString($map[$key] ?? null);

        return $map[$key];
    }

    /**
     * @return array<array-key, mixed>
     */
    private function decoded(string $line): array
    {
        $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
