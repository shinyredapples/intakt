<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Infrastructure;

use Intakt\Core\Ai\Contract\ReviewServerAccess;
use Intakt\Core\Review\Infrastructure\IssuedReviewServerAccess;
use Intakt\Core\Review\UseCase\ServeReviewTools\ReviewCredential;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(IssuedReviewServerAccess::class)]
#[UsesClass(ReviewCredential::class)]
#[UsesClass(ReviewServerAccess::class)]
final class IssuedReviewServerAccessTest extends TestCase
{
    public function testTheAccessNamesTheServerTheRoundsCredentialAndTheReadOnlyTools(): void
    {
        $round = Uuid::v7();

        $access = new IssuedReviewServerAccess(new ReviewCredential('secret'), 'http://localhost:7780')->forRound($round);

        self::assertInstanceOf(ReviewServerAccess::class, $access);
        self::assertSame('http://localhost:7780/mcp/review', $access->url);
        self::assertSame(new ReviewCredential('secret')->issueFor($round->toRfc4122()), $access->credential);
        self::assertSame(['read_file', 'list_directory', 'search_files'], $access->tools);
    }

    public function testACallWithoutARoundGetsNoAccess(): void
    {
        self::assertNotInstanceOf(ReviewServerAccess::class, new IssuedReviewServerAccess(new ReviewCredential('secret'), 'http://localhost:7780')->forRound(null));
    }

    public function testAnUnsetServerAddressGivesNoAccess(): void
    {
        self::assertNotInstanceOf(ReviewServerAccess::class, new IssuedReviewServerAccess(new ReviewCredential('secret'), '')->forRound(Uuid::v7()));
    }

    public function testAnEmptySecretGivesNoAccessInsteadOfFailing(): void
    {
        self::assertNotInstanceOf(ReviewServerAccess::class, new IssuedReviewServerAccess(new ReviewCredential(''), 'http://localhost:7780')->forRound(Uuid::v7()));
    }
}
