<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubForge;

use Intakt\Plugin\GitHubForge\Contract\GitHubForgeServices;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;

// The shipped setting, so a changed default is seen by a test and not only by the owner.
#[CoversNothing]
final class BudgetReserveSettingTest extends KernelTestCase
{
    public function testTheReserveKeepsAFifthOfTheHourByDefault(): void
    {
        self::bootKernel();

        self::assertSame(20, self::getContainer()->getParameter(GitHubForgeServices::RESERVE_PERCENT));
    }
}
