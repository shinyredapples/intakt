<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Infrastructure;

use Intakt\Core\Ai\Contract\ReviewServerAccess;
use Intakt\Core\Review\Infrastructure\IssuedReviewServerAccesses;
use Intakt\Core\Review\UseCase\ServeReviewTools\ReviewCredential;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\UuidV7;

#[CoversClass(IssuedReviewServerAccesses::class)]
#[UsesClass(ReviewServerAccess::class)]
#[UsesClass(ReviewCredential::class)]
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

    private function accesses(): IssuedReviewServerAccesses
    {
        return new IssuedReviewServerAccesses(new ReviewCredential('secret'), 'http://localhost:7780/mcp/review');
    }
}
