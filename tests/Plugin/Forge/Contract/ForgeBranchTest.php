<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\Forge\Contract;

use Intakt\Plugin\Forge\Contract\CommitId;
use Intakt\Plugin\Forge\Contract\ForgeBranch;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ForgeBranch::class)]
#[UsesClass(CommitId::class)]
final class ForgeBranchTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function releaseNames(): iterable
    {
        yield 'release alone' => ['release'];
        yield 'under release/' => ['release/1.0'];
        yield 'under releases/' => ['releases/2026-11'];
        yield 'after release-' => ['release-1.0'];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function otherNames(): iterable
    {
        yield 'a word that starts with release' => ['releasenotes'];
        yield 'release further down' => ['intakt/release/1.0'];
        yield 'an Intakt branch' => ['intakt/feature'];
    }

    #[DataProvider('releaseNames')]
    public function testABranchNamedAsAReleaseIsAReleaseBranchAndNotDeletable(string $name): void
    {
        $branch = $this->branch($name, false, false);

        self::assertTrue($branch->isRelease());
        self::assertFalse($branch->isDeletable());
    }

    #[DataProvider('otherNames')]
    public function testABranchWithAnotherNameIsNoReleaseBranchAndIsDeletable(string $name): void
    {
        $branch = $this->branch($name, false, false);

        self::assertFalse($branch->isRelease());
        self::assertTrue($branch->isDeletable());
    }

    public function testAProtectedBranchIsNotDeletable(): void
    {
        self::assertFalse($this->branch('intakt/feature', true, false)->isDeletable());
    }

    public function testADefaultBranchIsNotDeletable(): void
    {
        self::assertFalse(new ForgeBranch('trunk', new CommitId(str_repeat('a', 40)), true, false, false)->isDeletable());
    }

    public function testASymbolicBranchIsNotDeletable(): void
    {
        self::assertFalse($this->branch('intakt/feature', false, true)->isDeletable());
    }

    private function branch(string $name, bool $protected, bool $symbolic): ForgeBranch
    {
        return new ForgeBranch($name, new CommitId(str_repeat('a', 40)), false, $protected, $symbolic);
    }
}
