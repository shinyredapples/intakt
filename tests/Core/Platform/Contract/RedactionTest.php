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

    /**
     * @return iterable<string, array{string}>
     */
    public static function heldCredentialsJsonEscapes(): iterable
    {
        yield 'newline' => ["held\ncredential-tail"];
        yield 'tab and carriage return' => ["held\t\rcredential-tail"];
        yield 'other control character' => ["held\x01credential-tail"];
        yield 'accented letter' => ['held-é-credential-tail'];
        yield 'character outside the basic plane' => ["held-\u{1F511}-credential-tail"];
    }

    #[DataProvider('heldCredentialsJsonEscapes')]
    public function testItRedactsAHeldCredentialWhoseCharactersJsonEscapes(string $credential): void
    {
        $once = json_encode($credential, JSON_THROW_ON_ERROR);
        $twice = json_encode($once, JSON_THROW_ON_ERROR);

        $redacted = new Redaction()->redact($once.' '.$twice, $credential);

        self::assertStringNotContainsString('credential-tail', $redacted);
    }

    public function testItRedactsAHeldCredentialWrittenWithHexEscapes(): void
    {
        $credential = 'pa<ss>&\'"word';
        $encoded = json_encode($credential, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

        self::assertSame('[redacted]', trim(new Redaction()->redact($encoded, $credential), '"'));
    }

    public function testItRedactsALongHeldCredentialAndKeepsTheRest(): void
    {
        $credential = substr(str_repeat('Ab3/+x9Q', 1000), 0, 3000);

        $redacted = new Redaction()->redact('before '.$credential.' after', $credential);

        self::assertSame('before [redacted] after', $redacted);
    }

    public function testItKeepsTheHostOfAUrlWithUserinfo(): void
    {
        $redacted = new Redaction()->redact('clone https://deploy:hunter2@example.com/org/repo.git now');

        self::assertSame('clone https://[redacted]@example.com/org/repo.git now', $redacted);
    }

    public function testItRedactsTheUserinfoOfAUrlInJsonText(): void
    {
        $text = json_encode(['remote' => 'https://deploy:hunter2pass@example.com/r.git'], JSON_THROW_ON_ERROR);

        self::assertSame('{"remote":"https:\/\/[redacted]@example.com\/r.git"}', new Redaction()->redact($text));
    }

    public function testItRedactsTheUserinfoOfAUrlInJsonTextEncodedTwice(): void
    {
        $text = json_encode(json_encode(['remote' => 'https://deploy:hunter2pass@example.com/r.git'], JSON_THROW_ON_ERROR), JSON_THROW_ON_ERROR);

        self::assertStringNotContainsString('hunter2pass', new Redaction()->redact($text));
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
        yield 'single quotes around a double quote' => ["'Authorization'=>'Bearer \"x\" secretvalue1'", "'Authorization'=>'[redacted]'"];
        yield 'double quotes around a single quote' => ['{"Authorization":"Basic it\'s-secret"}', '{"Authorization":"[redacted]"}'];
    }

    public function testItRedactsADigestHeaderExportedWithVarExport(): void
    {
        $dump = var_export(['Authorization' => 'Digest username="alice", response="deadbeef01"', 'Accept' => 'text/html'], true);

        $redacted = new Redaction()->redact($dump);

        self::assertStringNotContainsString('deadbeef01', $redacted);
        self::assertStringContainsString("'Authorization' => '[redacted]'", $redacted);
        self::assertStringContainsString('text/html', $redacted);
    }

    public function testItRedactsAScalarHeaderWithADoubleQuoteExportedWithVarExport(): void
    {
        $dump = var_export(['Authorization' => 'Basic abc"def-secret'], true);

        self::assertStringNotContainsString('def-secret', new Redaction()->redact($dump));
    }

    public function testItRedactsAVarExportHeaderListPastANestedArray(): void
    {
        $dump = var_export(['Authorization' => [['first'], 'credential-tail'], 'Accept' => ['text/html']], true);

        $redacted = new Redaction()->redact($dump);

        self::assertStringNotContainsString('credential-tail', $redacted);
        self::assertStringContainsString('text/html', $redacted);
    }

    public function testItRedactsAVarExportHeaderListPastAParenthesisInsideAValue(): void
    {
        $dump = var_export(['Authorization' => ["first\n  ),\n)", 'credential-tail'], 'Accept' => ['text/html']], true);

        $redacted = new Redaction()->redact($dump);

        self::assertStringNotContainsString('credential-tail', $redacted);
        self::assertStringContainsString('text/html', $redacted);
    }

    public function testItRedactsAPrintRHeaderListPastAParenthesisLineInsideAValue(): void
    {
        $dump = print_r(['Authorization' => ["first\n)\ncredential-tail"], 'Accept' => 'text/html'], true);

        $redacted = new Redaction()->redact($dump);

        self::assertStringNotContainsString('credential-tail', $redacted);
        self::assertStringEndsWith('[Authorization] => [redacted]', $redacted);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function printRValuesMimickingTheClosingLine(): iterable
    {
        yield 'close line at the list indent' => ["first\n        )\ncredential-tail"];
        yield 'close line at the list indent then a blank line' => ["first\n        )\n\ncredential-tail"];
        yield 'close line at the list indent mimicking the next key' => ["first\n        )\n\n    [Accept] => credential-tail"];
    }

    #[DataProvider('printRValuesMimickingTheClosingLine')]
    public function testItRedactsAPrintRHeaderListPastAValueThatMimicsItsClosingLine(string $value): void
    {
        $dump = print_r(['Authorization' => [$value], 'Accept' => 'text/html'], true);

        $redacted = new Redaction()->redact($dump);

        self::assertStringNotContainsString('credential-tail', $redacted);
        self::assertStringEndsWith('[Authorization] => [redacted]', $redacted);
    }

    public function testItRedactsATruncatedPrintRHeaderListPastAForgedClosingLine(): void
    {
        $dump = print_r(['Authorization' => ["first\n        )\ncredential-tail"]], true);
        $truncated = explode('credential-tail', $dump)[0].'credential-tail';

        $redacted = new Redaction()->redact($truncated);

        self::assertStringNotContainsString('credential-tail', $redacted);
        self::assertStringEndsWith('[Authorization] => [redacted]', $redacted);
    }

    public function testItRedactsAPrintRHeaderListTogetherWithAListThatFollowsIt(): void
    {
        $dump = print_r(['Authorization' => ["first\n        )\ncredential-tail"], 'Accept' => ['text/html']], true);

        $redacted = new Redaction()->redact($dump);

        self::assertStringNotContainsString('credential-tail', $redacted);
        self::assertStringEndsWith('[Authorization] => [redacted]', $redacted);
    }

    public function testItRedactsAPrintRHeaderListPastANestedArray(): void
    {
        $dump = print_r(['Authorization' => [['first'], 'credential-tail'], 'Accept' => 'text/html'], true);

        $redacted = new Redaction()->redact($dump);

        self::assertStringNotContainsString('credential-tail', $redacted);
        self::assertStringEndsWith('[Authorization] => [redacted]', $redacted);
    }

    #[DataProvider('headerForms')]
    public function testItRedactsTheValueOfACredentialHeader(string $text, string $expected): void
    {
        self::assertSame($expected, new Redaction()->redact($text));
    }

    public function testItRedactsAHeaderListPrintedWithPrintR(): void
    {
        $dump = print_r(['Authorization' => ['Bearer abc123xyz'], 'Accept' => 'text/html'], true);

        $redacted = new Redaction()->redact($dump);

        self::assertStringNotContainsString('abc123xyz', $redacted);
        self::assertStringEndsWith('[Authorization] => [redacted]', $redacted);
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

        self::assertSame("['Authorization' => [redacted], 'Accept' => ['text/html']]", $redacted);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function bracketedValuesOfPlainHeaders(): iterable
    {
        yield 'bracket then semicolon' => ['Cookie: [a]; sid=topsecretvalue'];
        yield 'bracketed scheme' => ['Authorization: [Bearer] topsecretvalue'];
        yield 'bracketed key' => ['X-Api-Key: [k] topsecretvalue'];
        yield 'shell quoted' => ["curl -H 'Cookie: [a] sid=topsecretvalue'"];
        yield 'empty brackets' => ['Cookie: [] topsecretvalue'];
        yield 'single quoted scheme then semicolon' => ["Authorization: ['Bearer x'] ; topsecretvalue"];
        yield 'single quoted key' => ["X-Api-Key: ['k'] topsecretvalue"];
        yield 'single quoted element then a cookie' => ["Cookie: ['a'] sid=topsecretvalue"];
        yield 'double quoted element then a comma' => ['Cookie: ["a"], sid=topsecretvalue'];
        yield 'empty brackets then a bare comma' => ['X-Api-Key: [],topsecretvalue'];
        yield 'empty brackets then a comma' => ['Cookie: [], sid=topsecretvalue'];
        yield 'list opening later in an unquoted value' => ['X-Api-Key: part1 cookie: [z] topsecretvalue'];
        yield 'list opening later in a json quoted value' => ['{"cookie": "sess=abc; authorization=[1] topsecretvalue"}'];
        yield 'list opening later in a shell quoted value' => ["curl -H 'Authorization: Basic x cookie=[y] topsecretvalue'"];
        yield 'list opening later in a double quoted value' => ['Cookie: "prefix authorization: [abc] topsecretvalue"'];
        yield 'list opening later after a colon' => ['Cookie: prefix authorization: [abc] topsecretvalue'];
        yield 'quoted element without a separator after' => ['X-Api-Key: ["k"] topsecretvalue'];
        yield 'parenthesized' => ['Cookie: array (a) topsecretvalue'];
        yield 'closing quote without an opening quote' => ['Authorization": ["a"], topsecretvalue'];
        yield 'closing quote other than the opening quote' => ['\'Authorization": ["a"], topsecretvalue'];
        yield 'quoted key inside the value of an unquoted header' => ['Cookie: x; \'authorization\': ["a"], sid=topsecretvalue'];
    }

    #[DataProvider('bracketedValuesOfPlainHeaders')]
    public function testItRedactsABracketedHeaderValueWhole(string $text): void
    {
        $redacted = new Redaction()->redact($text);

        self::assertStringNotContainsString('topsecretvalue', $redacted);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function headerListsOfQuotedKeys(): iterable
    {
        yield 'json list' => ['{"authorization": ["Bearer abc123xyz"], "status": 200}', '"status": 200}'];
        yield 'var_export short array' => ["['Authorization' => ['Bearer abc123xyz'], 'Accept' => 'text/html']", "'Accept' => 'text/html']"];
        yield 'var_export array' => [var_export(['Authorization' => ['Bearer abc123xyz'], 'Accept' => 'text/html'], true), "'Accept' => 'text/html',"];
        yield 'json list after a quoted header list on the same line' => ['{"cookie": ["a"], "authorization": ["Bearer abc123xyz"], "status": 200}', '"status": 200}'];
    }

    #[DataProvider('headerListsOfQuotedKeys')]
    public function testItKeepsTheFieldAfterTheRedactedHeaderListOfAQuotedKey(string $text, string $field): void
    {
        $redacted = new Redaction()->redact($text);

        self::assertStringNotContainsString('abc123xyz', $redacted);
        self::assertStringContainsString('[redacted]', $redacted);
        self::assertStringContainsString($field, $redacted);
    }

    public function testItKeepsTheFieldAfterARedactedHeaderListWithABlankBeforeTheComma(): void
    {
        $redacted = new Redaction()->redact('{"cookie": ["a"] , "x": "ok"}');

        self::assertSame('{"cookie": [redacted] , "x": "ok"}', $redacted);
    }

    public function testItKeepsTheFieldsAfterEachOfTwoRedactedHeaderListsOnTheSameLine(): void
    {
        $redacted = new Redaction()->redact('{"authorization": ["a1b2c3"], "cookie": ["d4e5f6"], "status": 200}');

        self::assertSame('{"authorization": [redacted], "cookie": [redacted], "status": 200}', $redacted);
    }

    public function testItRedactsAHeaderValueThatStartsWithAHeldCredential(): void
    {
        $redacted = new Redaction()->redact('Cookie: held-session-value; other=second-secret', 'held-session-value');

        self::assertStringNotContainsString('second-secret', $redacted);
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
        yield 'private key block' => ['x '.str_repeat('-', 5).'BEGIN RSA PRIVATE KEY'.str_repeat('-', 5)."\n".str_repeat('QUJD', 8)."\n".str_repeat('-', 5).'END RSA PRIVATE KEY'.str_repeat('-', 5).' y'];
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
