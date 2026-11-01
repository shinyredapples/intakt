<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\Forge;

use Intakt\Plugin\Forge\Contract\ReviewComment;
use Intakt\Plugin\Forge\Contract\ReviewToken;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReviewComment::class)]
#[CoversClass(ReviewToken::class)]
final class ReviewCommentTest extends TestCase
{
    public function testItKeepsTheBodyAndTheToken(): void
    {
        $comment = new ReviewComment('Two reviewers approve.', new ReviewToken('round-7_token-0123456789'));

        self::assertSame('Two reviewers approve.', $comment->body);
        self::assertSame('round-7_token-0123456789', $comment->token->value);
    }

    public function testItRefusesAnEmptyBody(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ReviewComment(" \n", new ReviewToken('round-7-token-0123456789'));
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
