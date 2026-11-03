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
        yield 'an API key header' => ['x-api-key: some-value', 'x-api-key: [redacted]'];
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
        yield 'the word authorization in a sentence' => ['The authorization step failed.'];
        yield 'a short sk- word' => ['Use the sk-learn package.'];
    }

    #[DataProvider('textsWithoutSecrets')]
    public function testItLeavesATextWithoutSecretsUnchanged(string $text): void
    {
        self::assertSame($text, new Redaction('key-one')->of($text));
    }
}
