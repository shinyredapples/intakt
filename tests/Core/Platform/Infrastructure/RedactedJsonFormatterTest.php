<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Platform\Infrastructure;

use DateTimeImmutable;
use Intakt\Core\Platform\Contract\Redaction;
use Intakt\Core\Platform\Infrastructure\RedactedJsonFormatter;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(RedactedJsonFormatter::class)]
#[UsesClass(Redaction::class)]
final class RedactedJsonFormatterTest extends TestCase
{
    private const string CREDENTIAL = 'held-credential-value';

    public function testALoggedFailureCarriesItsClassMessageTraceAndItem(): void
    {
        $line = $this->formatted(['reviewer' => 'reviewer-7', 'exception' => $this->failure('The ledger refused the event.')]);

        self::assertSame('reviewer-7', $line['context']['reviewer']);
        self::assertSame(RuntimeException::class, $line['context']['exception']['class']);
        self::assertSame('The ledger refused the event.', $line['context']['exception']['message']);
        self::assertNotEmpty($line['context']['exception']['trace']);
    }

    public function testARecordIsOneLine(): void
    {
        $output = new RedactedJsonFormatter([], [])->format($this->record('A failure.', ['exception' => $this->failure("Two\nlines.")]));

        self::assertSame(1, substr_count($output, "\n"));
        self::assertStringEndsWith("\n", $output);
    }

    public function testARecordWithAConfiguredCredentialAUrlWithUserinfoAndAnAuthorizationHeaderIsWrittenRedacted(): void
    {
        $output = new RedactedJsonFormatter([self::CREDENTIAL], [])->format($this->record('Call to https://user:pass@example.com failed.', [
            'headers' => ['Authorization: Bearer abc123'],
            'exception' => $this->failure('The key '.self::CREDENTIAL.' was refused.'),
        ]));

        self::assertStringNotContainsString(self::CREDENTIAL, $output);
        self::assertStringNotContainsString('user:pass', $output);
        self::assertStringNotContainsString('abc123', $output);
        self::assertStringContainsString('The key [redacted] was refused.', $output);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function failureMessagesWithSecrets(): iterable
    {
        yield 'an authorization header on its own line' => ["GET /repos\r\nAuthorization: token SECRETVALUE0123\r\n"];
        yield 'a GitHub token on its own line' => ["token:\nghs_".str_repeat('a1', 18)];
        yield 'a provider key on its own line' => ["key:\nsk-ant-api03-".str_repeat('x', 24)];
        yield 'a quoted authorization header' => ['Authorization: "Bearer SECRETVALUE0123"'];
        yield 'an authorization header in embedded JSON' => ['{"Authorization":"Bearer SECRETVALUE0123"}'];
        yield 'a URL with a quote in its password' => ['https://alice:p"SECRETVALUE0123@example.com/repo'];
    }

    // The reviewers' probes: a JSON escape in the written line once hid these from the patterns.
    #[DataProvider('failureMessagesWithSecrets')]
    public function testASecretInAFailureMessageIsWrittenRedacted(string $message): void
    {
        $output = new RedactedJsonFormatter([], [])->format($this->record('A failure.', ['exception' => $this->failure($message)]));

        self::assertDoesNotMatchRegularExpression('~SECRETVALUE|ghs_a1|sk-ant~', $output);
    }

    public function testAHeaderMapKeepsItsNamesAndRedactsTheAuthorizationValue(): void
    {
        $line = $this->decoded(new RedactedJsonFormatter([], [])->format($this->record('A call failed.', ['headers' => ['Authorization' => ['Bearer abc123'], 'Accept' => ['json']]])));

        self::assertSame(['Authorization' => '[redacted]', 'Accept' => ['json']], $line['context']['headers']);
    }

    public function testARecordWithoutContextKeepsAnEmptyObject(): void
    {
        $output = new RedactedJsonFormatter([], [])->format($this->record('Nothing happened.', []));

        self::assertStringContainsString('"context":{}', $output);
    }

    public function testACredentialThatJsonEscapesIsRedactedToo(): void
    {
        $credential = 'quote"and\\slash';

        $output = new RedactedJsonFormatter([$credential], [])->format($this->record('A failure.', ['value' => $credential]));

        self::assertStringNotContainsString('quote', $output);
    }

    public function testAnUnsetCredentialIsIgnored(): void
    {
        $output = new RedactedJsonFormatter([null, ''], [])->format($this->record('Nothing secret here.', []));

        self::assertStringContainsString('Nothing secret here.', $output);
    }

    public function testTheCredentialOfAnEnabledAccountIsRedacted(): void
    {
        $output = new RedactedJsonFormatter([], ['account-key-value'])->format($this->record('Refused account-key-value.', []));

        self::assertStringContainsString('Refused [redacted].', $output);
    }

    public function testABatchIsRedacted(): void
    {
        $output = new RedactedJsonFormatter([self::CREDENTIAL], [])->formatBatch([$this->record(self::CREDENTIAL, []), $this->record('second', [])]);

        self::assertStringNotContainsString(self::CREDENTIAL, $output);
        self::assertStringContainsString('second', $output);
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array{context: array{reviewer: string, exception: array{class: string, message: string, trace: list<string>}}}
     */
    private function formatted(array $context): array
    {
        /** @var array{context: array{reviewer: string, exception: array{class: string, message: string, trace: list<string>}}} $line */
        $line = json_decode(new RedactedJsonFormatter([], [])->format($this->record('A failure.', $context)), true, flags: JSON_THROW_ON_ERROR);

        return $line;
    }

    /**
     * @return array{context: array<string, mixed>}
     */
    private function decoded(string $output): array
    {
        /** @var array{context: array<string, mixed>} $line */
        $line = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

        return $line;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function record(string $message, array $context): LogRecord
    {
        return new LogRecord(new DateTimeImmutable('2026-11-03T10:00:00+00:00'), 'app', Level::Error, $message, $context);
    }

    private function failure(string $message): RuntimeException
    {
        return new RuntimeException($message);
    }
}
