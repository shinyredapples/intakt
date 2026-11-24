<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubWorkSource\Domain;

use Intakt\Plugin\GitHubWorkSource\Domain\TaskBranchName;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TaskBranchName::class)]
final class TaskBranchNameTest extends TestCase
{
    public function testABranchOfTypeNumberAndWordsNamesItsTask(): void
    {
        self::assertTrue(TaskBranchName::names('feat/7-add-intake', 7));
        self::assertTrue(TaskBranchName::names('fix/3690-work-intake-queue-a-task', 3690));
    }

    public function testABranchOfTypeAndNumberAloneNamesItsTask(): void
    {
        self::assertTrue(TaskBranchName::names('docs/7', 7));
    }

    public function testABranchWhoseNumberOnlyStartsWithTheTaskNumberNamesAnotherTask(): void
    {
        self::assertFalse(TaskBranchName::names('feat/70-add-intake', 7));
        self::assertFalse(TaskBranchName::names('feat/7x', 7));
    }

    public function testABranchWithALeadingZeroNamesNoTask(): void
    {
        self::assertFalse(TaskBranchName::names('feat/07-add-intake', 7));
    }

    public function testABranchWithoutItsTypeOrWithTheNumberElsewhereNamesNoTask(): void
    {
        self::assertFalse(TaskBranchName::names('7-add-intake', 7));
        self::assertFalse(TaskBranchName::names('feat/add-intake-7', 7));
        self::assertFalse(TaskBranchName::names('team/feat/7-add-intake', 7));
        self::assertFalse(TaskBranchName::names('Feat/7-add-intake', 7));
    }
}
