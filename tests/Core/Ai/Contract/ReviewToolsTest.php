<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Ai\Contract;

use Intakt\Core\Ai\Contract\ReviewTools;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReviewTools::class)]
final class ReviewToolsTest extends TestCase
{
    public function testReviewToolsRefuseAnEmptyListWhenConstructed(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ReviewTools();
    }

    public function testReviewToolsKeepTheToolNamesTheyWereGivenInOrder(): void
    {
        self::assertSame(['read_file', 'list_directory'], [...new ReviewTools('read_file', 'list_directory')]);
    }
}
