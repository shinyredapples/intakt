<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Git\Contract;

use Intakt\Core\Git\Contract\GitEnvironment;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(GitEnvironment::class)]
final class GitEnvironmentTest extends TestCase
{
    public function testAVariableAddedLaterReplacesOneOfTheSameName(): void
    {
        $environment = new GitEnvironment(GIT_ALLOW_PROTOCOL: '', HOME: '/nonexistent')->with(GIT_ALLOW_PROTOCOL: 'file');

        self::assertSame(['GIT_ALLOW_PROTOCOL' => 'file', 'HOME' => '/nonexistent'], [...$environment]);
    }

    public function testAVariableSetToFalseIsKeptSoTheProcessUnsetsIt(): void
    {
        $environment = new GitEnvironment(GIT_ATTR_SOURCE: 'tree')->with(GIT_ATTR_SOURCE: false);

        self::assertSame(['GIT_ATTR_SOURCE' => false], [...$environment]);
    }

    public function testAddingAVariableLeavesTheOriginalAsItWas(): void
    {
        $original = new GitEnvironment(HOME: '/nonexistent');

        $original->with(HOME: '/elsewhere');

        self::assertSame(['HOME' => '/nonexistent'], [...$original]);
    }
}
