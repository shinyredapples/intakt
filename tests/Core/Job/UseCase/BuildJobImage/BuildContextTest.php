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

    private function contextOfAGeneratedDockerfile(): string
    {
        return new BuildContext(new ProjectFiles())->of(JobImagePlan::generated(JobImageSource::Generated, 'FROM scratch'), sys_get_temp_dir());
    }
}
