<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Git\Contract;

use Intakt\Core\Git\Contract\GitEnvironment;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(GitEnvironment::class)]
final class GitEnvironmentTest extends TestCase
{
    public function testAVariableAddedLaterReplacesOneOfTheSameNameInItsPlace(): void
    {
        $environment = GitEnvironment::of(
            GIT_ALLOW_PROTOCOL: '',
            HOME: '/nonexistent',
        )->with(GIT_ALLOW_PROTOCOL: 'file');

        self::assertSame(['GIT_ALLOW_PROTOCOL' => 'file', 'HOME' => '/nonexistent'], [...$environment]);
    }

    public function testAnUnsetVariableIsKeptAsFalseSoTheProcessDropsTheInheritedOne(): void
    {
        $environment = GitEnvironment::of(
            GIT_ATTR_SOURCE: 'tree',
            HOME: '/nonexistent',
        )->unsetting('GIT_ATTR_SOURCE', 'GIT_DIR');

        self::assertSame(['GIT_ATTR_SOURCE' => false, 'HOME' => '/nonexistent', 'GIT_DIR' => false], [...$environment]);
    }

    public function testAddingAVariableLeavesTheOriginalAsItWas(): void
    {
        $original = GitEnvironment::of(HOME: '/nonexistent');

        $original->with(HOME: '/elsewhere');

        self::assertSame(['HOME' => '/nonexistent'], [...$original]);
    }
}
