<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\LocalGitForge;

use Intakt\Core\Platform\Contract\ReportableFailure;
use Intakt\Plugin\LocalGitForge\Contract\LocalRepository;
use Intakt\Plugin\LocalGitForge\Contract\LocalRepositoryIncomplete;
use Intakt\Plugin\LocalGitForge\Contract\RepositoryNotFound;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(LocalRepository::class)]
#[CoversClass(LocalRepositoryIncomplete::class)]
#[CoversClass(RepositoryNotFound::class)]
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

    public function testABlankPathOrBaseBranchIsAFailureForThePersonRunningTheCommand(): void
    {
        $this->expectException(ReportableFailure::class);

        new LocalRepository('repository', ' ');
    }

    public function testAnExistingDirectoryIsFound(): void
    {
        $repository = LocalRepository::existingAt(__DIR__, 'main');

        self::assertSame(__DIR__, $repository->path);
    }

    public function testAPathThatIsNoDirectoryIsNotFound(): void
    {
        $this->expectExceptionObject(RepositoryNotFound::at(__FILE__));

        LocalRepository::existingAt(__FILE__, 'main');
    }
}
