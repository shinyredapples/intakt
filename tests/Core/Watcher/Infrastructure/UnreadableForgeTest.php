<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Watcher\Infrastructure;

use Intakt\Core\Watcher\Infrastructure\UnreadableForge;
use Intakt\Plugin\Forge\Contract\CommitId;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(UnreadableForge::class)]
final class UnreadableForgeTest extends TestCase
{
    public function testListingThePullRequestsFailsAsAForgeRead(): void
    {
        $this->expectException(ForgeReadFailed::class);

        new UnreadableForge()->listOpen('alpha', null);
    }

    public function testReadingTheCiOfAHeadFailsAsAForgeRead(): void
    {
        $this->expectException(ForgeReadFailed::class);

        new UnreadableForge()->ciStateOf('alpha', new CommitId(str_repeat('a', 40)));
    }
}
