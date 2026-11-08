<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use Override;
use Symfony\Component\AssetMapper\ImportMap\RemotePackageStorage;
use Symfony\Component\Filesystem\Filesystem;

// Pages that load the import map need the scripts importmap:install downloads, which needs the network; the page only maps their paths, so empty stand-ins make it render.
trait DownloadedScriptStandIns
{
    private const array VENDOR_ASSETS = ['@hotwired/stimulus/stimulus.index.js', '@hotwired/turbo/turbo.index.js'];

    // Because the asset mapper finds the downloaded scripts through this service, replacing it moves them without changing the configuration of the test environment.
    private const string REMOTE_PACKAGE_STORAGE = 'asset_mapper.importmap.remote_package_storage';

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->givenTheDownloadedScriptsExist();
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->removeTheStandIns();
        parent::tearDown();
    }

    private function givenTheDownloadedScriptsExist(): void
    {
        foreach (self::VENDOR_ASSETS as $asset) {
            new Filesystem()->dumpFile($this->standInFolder().'/'.$asset, '');
        }

        self::getContainer()->set(self::REMOTE_PACKAGE_STORAGE, new RemotePackageStorage($this->standInFolder()));
    }

    private function removeTheStandIns(): void
    {
        new Filesystem()->remove($this->standInFolder());
    }

    // Under assets/ so the asset mapper finds the stand-ins, and one folder per test worker, because each has a TMPDIR of its own, so none removes another's or a real download.
    private function standInFolder(): string
    {
        return dirname(__DIR__, 4).'/assets/vendor/downloaded_script_stand_ins_'.hash('xxh128', sys_get_temp_dir());
    }
}
