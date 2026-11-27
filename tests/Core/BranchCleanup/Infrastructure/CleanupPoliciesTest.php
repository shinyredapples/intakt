<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\BranchCleanup\Infrastructure;

use Intakt\Core\BranchCleanup\Domain\CleanupPolicy;
use Intakt\Core\BranchCleanup\Infrastructure\CleanupPolicies;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CleanupPolicies::class)]
#[UsesClass(CleanupPolicy::class)]
final class CleanupPoliciesTest extends TestCase
{
    public function testAProjectGetsTheSettingsItsProjectFileSets(): void
    {
        $policies = new CleanupPolicies(['beta' => ['clean_other_branches' => true, 'keep_branches' => ['experiments']]]);

        self::assertEquals(new CleanupPolicy(true, ['experiments']), $policies->of('beta'));
    }

    public function testAProjectWithoutCleanupKeysGetsIntaktsOwnBranchesOnlyAndNoneKeptByName(): void
    {
        $policies = new CleanupPolicies(['beta' => ['clean_other_branches' => true, 'keep_branches' => ['experiments']]]);

        self::assertEquals(new CleanupPolicy(false, []), $policies->of('gamma'));
    }
}
