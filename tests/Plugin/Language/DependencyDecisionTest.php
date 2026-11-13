<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\Language;

use Intakt\Plugin\Language\Contract\DependencyDecision;
use Intakt\Plugin\Language\Contract\DependencyFinding;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DependencyDecision::class)]
#[UsesClass(DependencyFinding::class)]
final class DependencyDecisionTest extends TestCase
{
    public function testADecisionWithoutFindingsIsRoutine(): void
    {
        self::assertFalse(DependencyDecision::of([], ['symfony/uid'])->needsOwner());
    }

    public function testADecisionWithAFindingNeedsTheOwner(): void
    {
        self::assertTrue(DependencyDecision::of([new DependencyFinding('acme/tool', 'is not from a trusted vendor')], [])->needsOwner());
    }

    public function testAnUnreadableFileNeedsTheOwnerAndSaysWhy(): void
    {
        $decision = DependencyDecision::unreadable('composer.lock', 'composer.lock cannot be read: Syntax error');

        self::assertTrue($decision->needsOwner());
        self::assertEquals([new DependencyFinding('composer.lock', 'composer.lock cannot be read: Syntax error')], $decision->findings);
    }

    public function testNewPackagesAreListedOnceAndSorted(): void
    {
        self::assertSame(['acme/tool', 'symfony/uid'], DependencyDecision::of([], ['symfony/uid', 'acme/tool', 'symfony/uid'])->newPackages);
    }

    public function testCombinedDecisionsKeepEveryFindingAndPackageOnce(): void
    {
        $first = DependencyDecision::of([new DependencyFinding('acme/tool', 'is not from a trusted vendor')], ['acme/tool']);
        $second = DependencyDecision::of([new DependencyFinding('acme/tool', 'is not from a trusted vendor'), new DependencyFinding('acme/other', 'was removed from composer.lock')], ['acme/tool']);

        $combined = DependencyDecision::combined($first, $second);

        self::assertEquals([new DependencyFinding('acme/tool', 'is not from a trusted vendor'), new DependencyFinding('acme/other', 'was removed from composer.lock')], $combined->findings);
        self::assertSame(['acme/tool'], $combined->newPackages);
    }

    public function testFindingsWhoseSubjectAndReasonOnlyJoinAlikeStayApart(): void
    {
        $decision = DependencyDecision::of([new DependencyFinding('a', 'bc'), new DependencyFinding('ab', 'c')], []);

        self::assertCount(2, $decision->findings);
    }
}
