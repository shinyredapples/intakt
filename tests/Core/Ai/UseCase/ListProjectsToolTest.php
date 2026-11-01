<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Ai\UseCase;

use Intakt\Core\Ai\Contract\TierResolver;
use Intakt\Core\Ai\UseCase\ListProjects\ListProjectsTool;
use Intakt\Core\Ai\UseCase\ListProjects\ProjectList;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ListProjectsTool::class)]
#[UsesClass(ProjectList::class)]
final class ListProjectsToolTest extends TestCase
{
    public function testItReturnsEveryConfiguredProject(): void
    {
        $resolver = self::createStub(TierResolver::class);
        $resolver->method('projects')->willReturn(['alpha', 'beta']);

        self::assertSame(['alpha', 'beta'], (new ListProjectsTool($resolver))()->projects);
    }

    public function testItReturnsAnEmptyListWhenNoProjectIsConfigured(): void
    {
        $resolver = self::createStub(TierResolver::class);
        $resolver->method('projects')->willReturn([]);

        self::assertSame([], (new ListProjectsTool($resolver))()->projects);
    }
}
