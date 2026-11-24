<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubForge\Contract;

use Intakt\Plugin\GitHubForge\Contract\IssueLabels;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(IssueLabels::class)]
final class IssueLabelsTest extends TestCase
{
    public function testIssueLabelsContainALabelTheyHold(): void
    {
        self::assertTrue(new IssueLabels('bug', 'intakt:ready')->contains('intakt:ready'));
    }

    public function testIssueLabelsMatchOnlyTheExactLabel(): void
    {
        $labels = new IssueLabels('intakt:ready');

        self::assertFalse($labels->contains('intakt:Ready'));
        self::assertFalse($labels->contains('intakt'));
    }

    public function testIssueLabelsKeepTheOrderGitHubAnswered(): void
    {
        self::assertSame(['b', 'a'], [...new IssueLabels('b', 'a')]);
    }
}
