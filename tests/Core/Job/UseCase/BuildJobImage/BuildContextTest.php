<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Job\UseCase\BuildJobImage;

use Intakt\Core\Job\UseCase\BuildJobImage\BuildContext;
use Intakt\Core\Job\UseCase\BuildJobImage\JobImagePlan;
use Intakt\Core\Job\UseCase\BuildJobImage\JobImageSource;
use Intakt\Core\Job\UseCase\BuildJobImage\ProjectFiles;
use PharData;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BuildContext::class)]
#[UsesClass(JobImagePlan::class)]
#[UsesClass(ProjectFiles::class)]
final class BuildContextTest extends TestCase
{
    private string $file = '';

    protected function tearDown(): void
    {
        if ('' !== $this->file) {
            new BuildContext(new ProjectFiles())->remove($this->file);
        }
    }

    public function testTheContextIsWrittenIntoAFolderOnlyItsOwnerCanEnter(): void
    {
        $this->file = $this->contextOfAGeneratedDockerfile();

        self::assertSame(0o700, fileperms(dirname($this->file)) & 0o777);
        self::assertSame('FROM scratch', new PharData($this->file)['Dockerfile']->getContent());
    }

    public function testRemovingAContextDeletesItsFileAndItsFolder(): void
    {
        $this->file = $this->contextOfAGeneratedDockerfile();

        new BuildContext(new ProjectFiles())->remove($this->file);

        self::assertFileDoesNotExist($this->file);
        self::assertDirectoryDoesNotExist(dirname($this->file));
    }

    private function contextOfAGeneratedDockerfile(): string
    {
        return new BuildContext(new ProjectFiles())->of(JobImagePlan::generated(JobImageSource::Generated, 'FROM scratch'), sys_get_temp_dir());
    }
}
