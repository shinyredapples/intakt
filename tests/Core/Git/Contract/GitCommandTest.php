<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Git\Contract;

use Intakt\Core\Git\Contract\GitCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(GitCommand::class)]
final class GitCommandTest extends TestCase
{
    public function testTheArgumentsKeepTheirOrder(): void
    {
        self::assertSame(['git', 'log', '-1'], [...new GitCommand('git', 'log', '-1')]);
    }

    public function testNamedArgumentsAreReindexedAsAList(): void
    {
        $command = new GitCommand(
            first: 'git',
            second: 'status',
        );

        self::assertSame(['git', 'status'], [...$command]);
    }
}
