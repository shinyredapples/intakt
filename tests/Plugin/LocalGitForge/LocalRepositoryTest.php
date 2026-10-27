<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\LocalGitForge;

use Intakt\Plugin\LocalGitForge\Contract\LocalRepository;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(LocalRepository::class)]
final class LocalRepositoryTest extends TestCase
{
    public function testALocalRepositoryKeepsItsPathAndBaseBranch(): void
    {
        $repository = new LocalRepository('repository', 'main');

        self::assertSame('repository', $repository->path);
        self::assertSame('main', $repository->baseBranch);
    }

    public function testABlankPathIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new LocalRepository(' ', 'main');
    }

    public function testABlankBaseBranchIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new LocalRepository('repository', ' ');
    }
}
