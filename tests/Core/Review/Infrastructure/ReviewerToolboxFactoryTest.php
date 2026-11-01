<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Infrastructure;

use Intakt\Core\Review\Contract\Checkout;
use Intakt\Core\Review\Infrastructure\Repository\DenyPathsOutsideCheckout;
use Intakt\Core\Review\Infrastructure\Repository\ListDirectory;
use Intakt\Core\Review\Infrastructure\Repository\ReadFile;
use Intakt\Core\Review\Infrastructure\Repository\SearchFiles;
use Intakt\Core\Review\Infrastructure\ReviewerToolboxFactory;
use Intakt\Tests\Core\Review\Infrastructure\Repository\ReviewCheckout;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReviewerToolboxFactory::class)]
#[UsesClass(DenyPathsOutsideCheckout::class)]
#[UsesClass(ReadFile::class)]
#[UsesClass(ListDirectory::class)]
#[UsesClass(SearchFiles::class)]
#[UsesClass(Checkout::class)]
final class ReviewerToolboxFactoryTest extends TestCase
{
    private ReviewCheckout $fixture;

    protected function setUp(): void
    {
        $this->fixture = new ReviewCheckout();
    }

    protected function tearDown(): void
    {
        $this->fixture->remove();
    }

    public function testEveryRepositoryToolIsReadOnlyAndNamesItsPathArgumentAsTheListenerExpects(): void
    {
        $tools = new ReviewerToolboxFactory()->create($this->fixture->checkout)->getTools();

        self::assertCount(3, $tools);

        foreach ($tools as $tool) {
            self::assertTrue($tool->getEffectHints()->readOnlyHint, $tool->getName());
            self::assertArrayHasKey(DenyPathsOutsideCheckout::PATH_ARGUMENT, $tool->getParameters()['properties'] ?? [], $tool->getName());
        }
    }
}
