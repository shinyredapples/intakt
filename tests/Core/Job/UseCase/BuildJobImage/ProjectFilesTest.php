<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Job\UseCase\BuildJobImage;

use Intakt\Core\Job\UseCase\BuildJobImage\JobImageNotBuilt;
use Intakt\Core\Job\UseCase\BuildJobImage\JobImagePlan;
use Intakt\Core\Job\UseCase\BuildJobImage\ProjectFiles;
use Intakt\Tests\Core\Git\TemporaryRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

#[CoversClass(ProjectFiles::class)]
#[UsesClass(JobImagePlan::class)]
#[UsesClass(JobImageNotBuilt::class)]
final class ProjectFilesTest extends TestCase
{
    private string $temporary = '';
    private string $repository = '';

    public function testTheFolderOfTheAddedFileIsRemovedWhenGitArchiveFails(): void
    {
        $repository = new TemporaryRepository();
        $this->repository = $repository->path;
        $repository->commitFile('README.md', 'a project');
        $this->temporary = sys_get_temp_dir().'/intakt-project-files-'.bin2hex(random_bytes(6));
        mkdir($this->temporary);
        // In a child process with a temporary folder of its own, because PHP reads sys_temp_dir only when it starts and the added file's folder has a random name.
        $child = <<<'PHP'
            require $argv[1];
            use Intakt\Core\Job\UseCase\BuildJobImage\JobImageNotBuilt;
            use Intakt\Core\Job\UseCase\BuildJobImage\JobImagePlan;
            use Intakt\Core\Job\UseCase\BuildJobImage\JobImageSource;
            use Intakt\Core\Job\UseCase\BuildJobImage\ProjectFiles;

            $plan = JobImagePlan::fromTree(JobImageSource::Dockerfile, 'missing', 'Dockerfile')->withReviewTools("FROM scratch\n");
            try {
                new ProjectFiles()->archiveAdding($argv[2], $plan, $argv[3]);
                echo 'archived';
            } catch (JobImageNotBuilt $failure) {
                echo $failure->getMessage();
            }
            PHP;
        $process = new Process([PHP_BINARY, '-d', 'sys_temp_dir='.$this->temporary, '-r', $child, '--', dirname(__DIR__, 5).'/vendor/autoload.php', $repository->path, $repository->path.'/context.tar']);

        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput().$process->getOutput());
        self::assertStringStartsWith($repository->path.' is not a git checkout with a commit that git can read: ', $process->getOutput());
        self::assertStringContainsString('missing', $process->getOutput());
        self::assertSame([], array_diff((array) scandir($this->temporary), ['.', '..']));
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove([$this->temporary, $this->repository]);
    }
}
