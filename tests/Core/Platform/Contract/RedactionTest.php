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
    public function testItRedactsEveryCredentialItHolds(): void
    {
        $redaction = new Redaction('key-one', 'key-two');

        self::assertSame('Found [redacted] in a log and [redacted] in a test.', $redaction->of('Found key-one in a log and key-two in a test.'));
    }

    public function testItRedactsACredentialThatContainsAnotherWhole(): void
    {
        self::assertSame('The token is [redacted].', new Redaction('123', 'abc-123-xyz')->of('The token is abc-123-xyz.'));
    }

    public function testItRedactsACredentialItHoldsInsideAJsonText(): void
    {
        $text = json_encode(['password' => 'private"credential/value'], JSON_THROW_ON_ERROR);

        self::assertSame('{"password":"[redacted]"}', new Redaction('private"credential/value')->of($text));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function textsWithSecrets(): iterable
    {
        yield 'a URL with a user and a password' => ['Could not reach https://builder:hunter2@example.com/repo.git.', 'Could not reach https://[redacted]@example.com/repo.git.'];
        yield 'a URL with a token as its user' => ['DSN postgresql://app_token@localhost/intakt', 'DSN postgresql://[redacted]@localhost/intakt'];
        yield 'a bearer authorization header' => ['Authorization: Bearer abc.def', 'Authorization: Bearer [redacted]'];
        yield 'a basic proxy authorization header' => ['proxy-authorization: Basic dXNlcjpwYXNz', 'proxy-authorization: Basic [redacted]'];
        yield 'an authorization header without a scheme' => ['Authorization: abcdef', 'Authorization: [redacted]'];
        yield 'an authorization header in JSON' => ['{"authorization":["Bearer abc"]}', '{"authorization":["Bearer [redacted]"]}'];
        yield 'a URL with a quote in its password' => ['https://alice:p"assword@example.com/repo', 'https://[redacted]@example.com/repo'];
        yield 'a URL with an @ in its password' => ['https://alice:p@ss@example.com/repo', 'https://[redacted]@example.com/repo'];
        yield 'an authorization header on its own line' => ["GET /repos\r\nAuthorization: token abc123\r\n", "GET /repos\r\nAuthorization: token [redacted]\r\n"];
        yield 'a quoted authorization header' => ['Authorization: "Bearer abc123"', 'Authorization: "Bearer [redacted]"'];
        yield 'an authorization header without a closing quote' => ['Authorization: "Bearer abc123', 'Authorization: "Bearer [redacted]"'];
        yield 'an authorization header in a JSON object' => ['{"Authorization":"Bearer abc123","Accept":"json"}', '{"Authorization":"Bearer [redacted]","Accept":"json"}'];
        yield 'a Digest authorization header' => ['Authorization: Digest username="alice", nonce="n0nce", response="r3sponse"', 'Authorization: Digest [redacted]'];
        yield 'a Digest authorization header with spaces around its equals signs' => ['Authorization: Digest username = "alice", response = "r3sponse"', 'Authorization: Digest [redacted]'];
        yield 'an API key header' => ['x-api-key: some-value', 'x-api-key: [redacted]'];
        yield 'a header in var_export output' => ["'Authorization' => 'Bearer abc123',", "'Authorization' => 'Bearer [redacted]',"];
        yield 'a shell-quoted header in var_export output' => ["'\\''Authorization'\\'' => '\\''Bearer abc123'\\''", "'\\''Authorization'\\'' => '\\''Bearer [redacted]'\\''"];
        yield 'a header in print_r output' => ["[Authorization] => Bearer abc123\n[Accept] => json", "[Authorization] => Bearer [redacted]\n[Accept] => json"];
        yield 'a header value with a comma' => ['Authorization: Bearer abc,def', 'Authorization: Bearer [redacted]'];
        yield 'a header in JSON inside JSON' => ['{\"Authorization\":\"Bearer abc123\",\"Accept\":\"json\"}', '{\"Authorization\":\"Bearer [redacted]\",\"Accept\":\"json\"}'];
        yield 'a URL with a slash in its password' => ['https://alice:pa/ss@example.com/r', 'https://[redacted]@example.com/r'];
        yield 'a URL with a # in its password' => ['https://alice:pa#ss@example.com/r', 'https://[redacted]@example.com/r'];
        yield 'a URL with escaped slashes in JSON' => ['{"url":"https:\/\/alice:hunter2@example.com\/repo"}', '{"url":"https:\/\/[redacted]@example.com\/repo"}'];
        yield 'a GitHub installation token' => ['token ghs_'.str_repeat('a1', 18).' expired', 'token [redacted] expired'];
        yield 'a fine-grained GitHub token' => ['github_pat_'.str_repeat('Ab1', 10), '[redacted]'];
        yield 'a provider API key' => ['key sk-ant-api03-'.str_repeat('x', 24), 'key [redacted]'];
        // Joined here, so no secret scan of this file mistakes the fixtures for real ones.
        yield 'a JSON web token' => ['jwt '.implode('.', ['eyJhbGciOiJSUzI1NiJ9', 'eyJpc3MiOiIxIn0', 'c2lnbmF0dXJl']), 'jwt [redacted]'];
        yield 'a private key' => [sprintf("-----BEGIN RSA %s-----\nMIIE\n-----END RSA %s-----", 'PRIVATE KEY', 'PRIVATE KEY'), '[redacted]'];
    }

    #[DataProvider('textsWithSecrets')]
    public function testItRedactsASecretItDoesNotHold(string $text, string $redacted): void
    {
        self::assertSame($redacted, new Redaction()->of($text));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function textsWithoutSecrets(): iterable
    {
        yield 'plain text' => ['Two reviewers approve.'];
        yield 'a URL without userinfo' => ['See https://example.com/a@b and mail me@example.com.'];
        yield 'a URL without userinfo with escaped slashes' => ['{"url":"https:\/\/example.com\/a@b"}'];
        yield 'the word authorization in a sentence' => ['The authorization step failed.'];
        yield 'a short sk- word' => ['Use the sk-learn package.'];
    }

    #[DataProvider('textsWithoutSecrets')]
    public function testItLeavesATextWithoutSecretsUnchanged(string $text): void
    {
        self::assertSame($text, new Redaction('key-one')->of($text));
    }

    public function testAnAuthorizationHeaderNamesACredentialInAnyCase(): void
    {
        self::assertTrue(new Redaction()->namesACredential('Proxy-Authorization'));
    }

    public function testAnotherHeaderNamesNoCredential(): void
    {
        self::assertFalse(new Redaction()->namesACredential('authorization-step'));
    }
}
