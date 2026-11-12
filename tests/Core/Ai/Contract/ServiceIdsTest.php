<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Ai\Contract;

use Intakt\Core\Ai\Contract\AiServices;
use Intakt\Core\Budget\Contract\BudgetServices;
use Intakt\Core\Review\Contract\ReviewServices;
use Intakt\Core\ReviewerForLoop\Contract\ReviewerForLoopServices;
use Intakt\Core\Watcher\Contract\WatcherServices;
use Intakt\Plugin\GitHubForge\Contract\GitHubForgeServices;
use Intakt\Plugin\SymfonyAiVendor\Contract\SymfonyAiVendorServices;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversClass(AiServices::class)]
#[CoversClass(BudgetServices::class)]
#[CoversClass(ReviewServices::class)]
#[CoversClass(ReviewerForLoopServices::class)]
#[CoversClass(WatcherServices::class)]
#[CoversClass(GitHubForgeServices::class)]
#[CoversClass(SymfonyAiVendorServices::class)]
final class ServiceIdsTest extends TestCase
{
    /**
     * @param class-string $ids
     */
    #[DataProvider('idsClasses')]
    public function testEveryIdIsPrefixedWithIntakt(string $ids): void
    {
        $values = array_values(new ReflectionClass($ids)->getConstants());

        self::assertNotSame([], $values);
        self::assertFalse(array_any($values, static fn (mixed $value): bool => !is_string($value) || !str_starts_with($value, 'intakt.')));
    }

    /**
     * @return iterable<string, array{class-string}>
     */
    public static function idsClasses(): iterable
    {
        foreach ([AiServices::class, BudgetServices::class, ReviewServices::class, ReviewerForLoopServices::class, WatcherServices::class, GitHubForgeServices::class, SymfonyAiVendorServices::class] as $ids) {
            yield $ids => [$ids];
        }
    }
}
