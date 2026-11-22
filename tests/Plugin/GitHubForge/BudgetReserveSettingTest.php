<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubForge;

use Intakt\Plugin\GitHubForge\Contract\GitHubForgeServices;
use Intakt\Tests\Support\CompiledContainers;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Component\Filesystem\Filesystem;

// The setting as the container is built, so a changed default or an owner value that no longer arrives is seen by a test and not only by the owner.
#[CoversNothing]
final class BudgetReserveSettingTest extends KernelTestCase
{
    private string $file = '';

    private mixed $savedConfig = null;

    public static function tearDownAfterClass(): void
    {
        self::compiledContainers()->remove();
        parent::tearDownAfterClass();
    }

    public function testTheReserveKeepsAFifthOfTheHourWhenTheOwnerSetsNone(): void
    {
        $this->bootWith('');

        self::assertSame(20, self::getContainer()->getParameter(GitHubForgeServices::RESERVE_PERCENT));
    }

    public function testTheReserveIsTheShareTheOwnerSetsInTheProjectFile(): void
    {
        $this->bootWith("github:\n    reserve_percent: 35\n");

        self::assertSame(35, self::getContainer()->getParameter(GitHubForgeServices::RESERVE_PERCENT));
    }

    protected function setUp(): void
    {
        $this->savedConfig = $_SERVER['INTAKT_CONFIG'] ?? null;
        $this->file = sys_get_temp_dir().'/intakt-github-reserve-'.bin2hex(random_bytes(4)).'.yaml';
    }

    protected function tearDown(): void
    {
        unset($_SERVER['INTAKT_CONFIG']);
        if (null !== $this->savedConfig) {
            $_SERVER['INTAKT_CONFIG'] = $this->savedConfig;
        }

        parent::tearDown();
        self::compiledContainers()->restoreTheSharedCache();
        new Filesystem()->remove($this->file);
    }

    private function bootWith(string $contents): void
    {
        new Filesystem()->dumpFile($this->file, $contents);
        $_SERVER['INTAKT_CONFIG'] = $this->file;
        // Keyed by the content, because the path changes per test while the content is the only build input this class sets.
        self::compiledContainers()->useFor([$contents]);
        self::bootKernel();
    }

    private static function compiledContainers(): CompiledContainers
    {
        return CompiledContainers::beside(self::createKernel()->getCacheDir(), 'github-reserve');
    }
}
