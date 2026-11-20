<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Infrastructure;

use Intakt\Core\Ai\Contract\ReviewServerAccess;
use Intakt\Core\Review\Contract\NoReviewServerAccess;
use Intakt\Core\Review\Infrastructure\IssuedReviewServerAccesses;
use Intakt\Core\Review\UseCase\ServeReviewTools\ReviewCredential;
use Intakt\Core\Review\UseCase\ServeReviewTools\ReviewCredentialNeedsSecret;
use LogicException;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\UuidV7;

#[CoversClass(IssuedReviewServerAccesses::class)]
#[UsesClass(ReviewServerAccess::class)]
#[UsesClass(ReviewCredential::class)]
#[UsesClass(ReviewCredentialNeedsSecret::class)]
#[UsesClass(NoReviewServerAccess::class)]
final class IssuedReviewServerAccessesTest extends TestCase
{
    public function testTheAccessNamesTheServersReadOnlyToolsAsItServesThem(): void
    {
        $access = $this->accesses()->forRound(new UuidV7());

        self::assertSame(['read_file', 'list_directory', 'search_files'], $access->tools);
    }

    public function testTheAccessCarriesTheCredentialOfItsRound(): void
    {
        $round = new UuidV7();
        $credential = new ReviewCredential('secret');

        $access = new IssuedReviewServerAccesses($credential, 'http://localhost:7780/mcp/review')->forRound($round);

        self::assertSame($credential->issueFor($round->toRfc4122()), $access->bearer);
        self::assertSame('http://localhost:7780/mcp/review', $access->url);
    }

    public function testNoAccessIsIssuedWithoutASecretToSignItsCredential(): void
    {
        $this->expectException(NoReviewServerAccess::class);

        new IssuedReviewServerAccesses(new ReviewCredential(''), 'http://localhost:7780/mcp/review')->forRound(new UuidV7());
    }

    public function testTheBlockedAccessKeepsTheMissingSecretAsItsReason(): void
    {
        try {
            new IssuedReviewServerAccesses(new ReviewCredential(''), 'http://localhost:7780/mcp/review')->forRound(new UuidV7());
            self::fail('An access was issued without a secret.');
        } catch (NoReviewServerAccess $blocked) {
            self::assertInstanceOf(ReviewCredentialNeedsSecret::class, $blocked->getPrevious());
        }
    }

    public function testAnUnexpectedFailureWhileIssuingPassesThroughInsteadOfBlockingTheAccess(): void
    {
        $round = new class extends UuidV7 {
            #[Override]
            public function toRfc4122(): string
            {
                throw new LogicException('The round could not be written.');
            }
        };

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIs('The round could not be written.');

        $this->accesses()->forRound($round);
    }

    private function accesses(): IssuedReviewServerAccesses
    {
        return new IssuedReviewServerAccesses(new ReviewCredential('secret'), 'http://localhost:7780/mcp/review');
    }
}
