<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Job\UseCase\BuildJobImage;

use Intakt\Core\Job\UseCase\BuildJobImage\BuildContext;
use Intakt\Core\Job\UseCase\BuildJobImage\JobImagePlan;
use Intakt\Core\Job\UseCase\BuildJobImage\JobImageSource;
use Intakt\Core\Job\UseCase\BuildJobImage\ProjectFiles;
use LogicException;
use PharData;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

#[CoversClass(BuildContext::class)]
#[UsesClass(JobImagePlan::class)]
#[UsesClass(ProjectFiles::class)]
final class BuildContextTest extends TestCase
{
    private string $file = '';

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

    public function testTheUploadHandsOutTheArchiveInChunksAndRemovesItAtItsEnd(): void
    {
        $this->file = $this->contextOfAGeneratedDockerfile();
        $archive = (string) file_get_contents($this->file);
        $upload = new BuildContext(new ProjectFiles())->upload($this->file);

        $first = $upload(512);
        $read = $first;
        while ('' !== ($chunk = $upload(4096))) {
            $read .= $chunk;
        }

        self::assertSame(512, strlen($first));
        self::assertSame($archive, $read);
        self::assertDirectoryDoesNotExist(dirname($this->file));
        self::assertSame('', $upload(4096));
    }

    public function testTheUploadKeepsTheArchiveUntilItsEnd(): void
    {
        $this->file = $this->contextOfAGeneratedDockerfile();
        $upload = new BuildContext(new ProjectFiles())->upload($this->file);

        $upload(512);

        self::assertFileExists($this->file);
    }

    public function testAContextIsUploadedAndRemovedWhenTheTemporaryDirectoryEndsInSlashes(): void
    {
        // In a child process, because PHP reads sys_temp_dir only when it starts.
        $child = <<<'PHP'
            require $argv[1];
            $context = new Intakt\Core\Job\UseCase\BuildJobImage\BuildContext(new Intakt\Core\Job\UseCase\BuildJobImage\ProjectFiles());
            $plan = Intakt\Core\Job\UseCase\BuildJobImage\JobImagePlan::generated(Intakt\Core\Job\UseCase\BuildJobImage\JobImageSource::Generated, 'FROM scratch');
            $file = $context->of($plan, sys_get_temp_dir());
            try {
                $upload = $context->upload($file);
                $bytes = 0;
                while ('' !== ($chunk = $upload(512))) {
                    $bytes += strlen($chunk);
                }
                echo json_encode(['temporary' => sys_get_temp_dir(), 'bytes' => $bytes, 'left' => is_dir(dirname($file))]);
            } finally {
                new Symfony\Component\Filesystem\Filesystem()->remove(dirname($file));
            }
            PHP;
        $process = new Process([PHP_BINARY, '-d', 'sys_temp_dir='.sys_get_temp_dir().'///', '-r', $child, '--', dirname(__DIR__, 5).'/vendor/autoload.php']);

        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput().$process->getOutput());
        $seen = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($seen);
        self::assertIsString($seen['temporary']);
        self::assertStringEndsWith('/', $seen['temporary']);
        self::assertGreaterThan(0, $seen['bytes']);
        self::assertFalse($seen['left']);
    }

    #[DataProvider('pathsThatAreNoContext')]
    public function testRemovingRefusesAPathThatIsNoContextAndKeepsItsFolder(string $name): void
    {
        $folder = sys_get_temp_dir().'/'.$name;
        new Filesystem()->dumpFile($folder.'/context.tar', 'keep');

        try {
            new BuildContext(new ProjectFiles())->remove($folder.'/context.tar');
            self::fail('The path was accepted.');
        } catch (LogicException $refused) {
            self::assertStringContainsString('is not a build context', $refused->getMessage());
        } finally {
            $kept = is_file($folder.'/context.tar');
            new Filesystem()->remove(sys_get_temp_dir().'/'.explode('/', $name)[0]);
        }

        self::assertTrue($kept);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function pathsThatAreNoContext(): iterable
    {
        yield 'a folder of another name' => ['intakt-other-'.bin2hex(random_bytes(4))];
        yield 'a context folder one level deeper' => ['intakt-outer-'.bin2hex(random_bytes(4)).'/intakt-image-inner'];
    }

    public function testRemovingRefusesAFileOfAnotherName(): void
    {
        $this->file = $this->contextOfAGeneratedDockerfile();

        $this->expectException(LogicException::class);

        new BuildContext(new ProjectFiles())->remove(dirname($this->file).'/other.tar');
    }

    protected function tearDown(): void
    {
        if ('' !== $this->file) {
            new BuildContext(new ProjectFiles())->remove($this->file);
        }
    }

    private function contextOfAGeneratedDockerfile(): string
    {
        return new BuildContext(new ProjectFiles())->of(JobImagePlan::generated(JobImageSource::Generated, 'FROM scratch'), sys_get_temp_dir());
    }
}
