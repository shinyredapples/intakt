<?php

declare(strict_types=1);

namespace Intakt\Tests\Support;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

#[CoversClass(ProjectCopy::class)]
final class ProjectCopyTest extends TestCase
{
    private const string ROOT = __DIR__.'/../..';

    private string $project;

    public function testAVendorLeftByAnEarlierRunOfTheSharedProjectIsReplacedByTheCheckouts(): void
    {
        new Filesystem()->dumpFile($this->project.'/vendor/stale-marker', 'from an older checkout');

        $this->refresh();

        self::assertFileDoesNotExist($this->project.'/vendor/stale-marker');
        self::assertFileExists($this->project.'/vendor/autoload.php');
    }

    public function testAVendorWithOtherPackagesThanTheCheckoutsIsReplacedEvenWhenItsAutoloadFileIsTheCheckouts(): void
    {
        $this->refresh();
        $vendor = $this->project.'/vendor';
        new Filesystem()->remove($vendor.'/composer/installed.json');
        new Filesystem()->dumpFile($vendor.'/composer/installed.json', '[]');
        new Filesystem()->dumpFile($vendor.'/stale-marker', 'from an older checkout');

        $this->refresh();

        self::assertFileDoesNotExist($vendor.'/stale-marker');
        self::assertFileEquals(self::ROOT.'/vendor/composer/installed.json', $vendor.'/composer/installed.json');
    }

    public function testAVendorCopiedWithoutLinksIsReusedWhenItsPackagesAreTheCheckouts(): void
    {
        $this->refresh();
        $autoload = $this->project.'/vendor/autoload.php';
        new Filesystem()->remove($autoload);
        new Filesystem()->copy(self::ROOT.'/vendor/autoload.php', $autoload);
        clearstatcache();
        $copied = fileinode($autoload);

        $this->refresh();

        clearstatcache();
        self::assertSame($copied, fileinode($autoload));
    }

    protected function setUp(): void
    {
        $this->project = sys_get_temp_dir().'/intakt-project-copy-test-'.bin2hex(random_bytes(6));
        new Filesystem()->mkdir($this->project);
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->project);
    }

    private function refresh(): void
    {
        ProjectCopy::refresh(self::ROOT, $this->project, 'composer.json');
    }
}
