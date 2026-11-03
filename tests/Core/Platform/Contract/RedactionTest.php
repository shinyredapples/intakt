<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Platform\Contract;

use Intakt\Core\Platform\Contract\Redaction;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Redaction::class)]
final class RedactionTest extends TestCase
{
    public function testItRedactsAHeldCredentialWhereverItAppears(): void
    {
        $redacted = new Redaction()->redact('first key-one, then key-one again', 'key-one');

        self::assertSame('first [redacted], then [redacted] again', $redacted);
    }

    public function testItRedactsACredentialThatContainsAnotherWhole(): void
    {
        $redacted = new Redaction()->redact('value key-one-and-more here', 'key-one', 'key-one-and-more');

        self::assertSame('value [redacted] here', $redacted);
    }

    public function testItRedactsAHeldCredentialEncodedTwice(): void
    {
        $credential = 'pa"ss/wo\\rd';
        $encoded = json_encode(json_encode($credential, JSON_THROW_ON_ERROR), JSON_THROW_ON_ERROR);

        $redacted = new Redaction()->redact('log '.$encoded.' end', $credential);

        self::assertSame('log "\\"[redacted]\\"" end', $redacted);
    }

    public function testItRedactsAHeldCredentialEncodedThreeTimes(): void
    {
        $credential = 'pa"ss/wo\\rd';
        $encoded = json_encode(json_encode(json_encode($credential, JSON_THROW_ON_ERROR), JSON_THROW_ON_ERROR), JSON_THROW_ON_ERROR);

        $redacted = new Redaction()->redact('log '.$encoded.' end', $credential);

        self::assertStringNotContainsString('wo', $redacted);
        self::assertStringNotContainsString('ss', $redacted);
        self::assertStringContainsString('[redacted]', $redacted);
    }

    public function testItKeepsTheHostOfAUrlWithUserinfo(): void
    {
        $redacted = new Redaction()->redact('clone https://deploy:hunter2@example.com/org/repo.git now');

        self::assertSame('clone https://[redacted]@example.com/org/repo.git now', $redacted);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function headerForms(): iterable
    {
        yield 'plain text' => ['Authorization: Bearer abc123xyz', 'Authorization: [redacted]'];
        yield 'shell quoted' => ['curl -H '."'Authorization: token abc123xyz' https://example.com", 'curl -H '."'Authorization: [redacted]' https://example.com"];
        yield 'quoted' => ['{"Authorization": "Basic abc123xyz"}', '{"Authorization": "[redacted]"}'];
        yield 'deep json' => ['{"a":{"b":[{"headers":{"Authorization":"Bearer abc123xyz"}}]}}', '{"a":{"b":[{"headers":{"Authorization":"[redacted]"}}]}}'];
        yield 'escaped json' => ['{"msg":"{\"Authorization\":\"Bearer abc123xyz\"}"}', '{"msg":"{\"Authorization\":\"[redacted]\"}"}'];
        yield 'digest in json' => [
            '{"Authorization":"Digest username=\"alice\", realm=\"r\", response=\"deadbeef01\""}',
            '{"Authorization":"[redacted]"}',
        ];
        yield 'escaped quote in json' => ['{"Authorization":"abc\"tail123","next":"ok"}', '{"Authorization":"[redacted]","next":"ok"}'];
        yield 'digest in text' => ['Authorization: Digest username="alice", response="deadbeef01"', 'Authorization: [redacted]'];
        yield 'cookie' => ["Cookie: session=abc123xyz\nnext", "Cookie: [redacted]\nnext"];
        yield 'print_r scalar' => ['[Authorization] => Bearer abc123xyz', '[Authorization] => [redacted]'];
    }

    #[DataProvider('headerForms')]
    public function testItRedactsTheValueOfACredentialHeader(string $text, string $expected): void
    {
        self::assertSame($expected, new Redaction()->redact($text));
    }

    public function testItRedactsAHeaderListPrintedWithPrintR(): void
    {
        $dump = print_r(['Authorization' => ['Bearer abc123xyz'], 'Accept' => ['text/html']], true);

        $redacted = new Redaction()->redact($dump);

        self::assertStringNotContainsString('abc123xyz', $redacted);
        self::assertStringContainsString('text/html', $redacted);
    }

    public function testItRedactsAHeaderListExportedWithVarExport(): void
    {
        $dump = var_export(['Authorization' => ['Bearer abc123xyz', 'second-value'], 'Accept' => ['text/html']], true);

        $redacted = new Redaction()->redact($dump);

        self::assertStringNotContainsString('abc123xyz', $redacted);
        self::assertStringNotContainsString('second-value', $redacted);
        self::assertStringContainsString('text/html', $redacted);
    }

    public function testItRedactsAShortArrayHeaderListToItsEnd(): void
    {
        $redacted = new Redaction()->redact("['Authorization' => ['Bearer abc123xyz', 'x]y'], 'Accept' => ['text/html']]");

        self::assertSame("['Authorization' => [redacted]'Accept' => ['text/html']]", $redacted);
    }

    public function testItRedactsToTheEndOfTheTextWhenAHeaderListNeverCloses(): void
    {
        $redacted = new Redaction()->redact("[Authorization] => Array\n(\n    [0] => Bearer abc123xyz\n    [1] => more");

        self::assertStringNotContainsString('abc123xyz', $redacted);
        self::assertStringNotContainsString('more', $redacted);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function secretShapes(): iterable
    {
        yield 'github token' => ['token ghp_'.str_repeat('a1', 18).' end'];
        yield 'anthropic style key' => ['key sk-'.str_repeat('ab', 15).' end'];
        yield 'aws key id' => ['id AKIA'.str_repeat('Q', 16).' end'];
        yield 'private key block' => ["x ".str_repeat('-', 5).'BEGIN RSA PRIVATE KEY'.str_repeat('-', 5)."\n".str_repeat('QUJD', 8)."\n".str_repeat('-', 5).'END RSA PRIVATE KEY'.str_repeat('-', 5).' y'];
    }

    #[DataProvider('secretShapes')]
    public function testItRedactsASecretShapeWithoutHoldingIt(string $text): void
    {
        $redacted = new Redaction()->redact($text);

        self::assertStringContainsString('[redacted]', $redacted);
        self::assertStringNotContainsString('QUJD', $redacted);
        self::assertStringNotContainsString('a1a1', $redacted);
        self::assertStringNotContainsString('abab', $redacted);
        self::assertStringNotContainsString('QQQQ', $redacted);
    }

    public function testItLeavesATextWithoutSecretsUnchanged(): void
    {
        $text = "Two reviewers approve.\nSee https://example.com/a/b?x=1 and the header Accept: text/html.";

        self::assertSame($text, new Redaction()->redact($text));
    }
}
