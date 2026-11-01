<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\Forge;

use Intakt\Plugin\Forge\Contract\JobCredentials;
use Intakt\Plugin\Forge\Contract\ReviewComment;
use Intakt\Plugin\Forge\Contract\ReviewToken;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReviewComment::class)]
#[CoversClass(ReviewToken::class)]
#[CoversClass(JobCredentials::class)]
final class ReviewCommentTest extends TestCase
{
    private const string TOKEN = 'round-7_token-0123456789';

    public function testItKeepsTheBodyAndTheToken(): void
    {
        $comment = new ReviewComment('Two reviewers approve.', new ReviewToken(self::TOKEN), new JobCredentials());

        self::assertSame('Two reviewers approve.', $comment->body);
        self::assertSame(self::TOKEN, $comment->token->value);
    }

    public function testItRefusesAnEmptyBody(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ReviewComment(" \n", new ReviewToken(self::TOKEN), new JobCredentials());
    }

    public function testItRedactsEveryValueTheJobHeld(): void
    {
        $comment = new ReviewComment('Found key-one in a log and key-two in a test.', new ReviewToken(self::TOKEN), new JobCredentials('key-one', 'key-two'));

        self::assertSame('Found [redacted] in a log and [redacted] in a test.', $comment->redactedBody());
    }

    public function testItRedactsACredentialThatContainsAnotherWhole(): void
    {
        $comment = new ReviewComment('The token is abc-123-xyz.', new ReviewToken(self::TOKEN), new JobCredentials('123', 'abc-123-xyz'));

        self::assertSame('The token is [redacted].', $comment->redactedBody());
    }

    public function testItLeavesABodyWithoutCredentialsUnchanged(): void
    {
        $comment = new ReviewComment('Two reviewers approve.', new ReviewToken(self::TOKEN), new JobCredentials('key-one'));

        self::assertSame('Two reviewers approve.', $comment->redactedBody());
    }

    public function testItRefusesAnEmptyCredentialValue(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new JobCredentials('key-one', '');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedTokens(): iterable
    {
        yield 'too short' => ['round-7-token'];
        yield 'too long' => [str_repeat('a', 129)];
        yield 'closing the marker' => ['round-7-token-01234 -->'];
        yield 'a new line' => ["round-7-token-0123456789\n"];
    }

    #[DataProvider('malformedTokens')]
    public function testItRefusesAMalformedToken(string $token): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ReviewToken($token);
    }

    public function testItAcceptsTokensAtBothLengthLimits(): void
    {
        self::assertSame(16, strlen(new ReviewToken(str_repeat('a', 16))->value));
        self::assertSame(128, strlen(new ReviewToken(str_repeat('Z', 128))->value));
    }
}
