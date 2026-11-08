<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use Override;
use Symfony\Component\AssetMapper\AssetMapper;
use Symfony\Component\AssetMapper\AssetMapperRepository;
use Symfony\Component\AssetMapper\CompiledAssetMapperConfigReader;
use Symfony\Component\AssetMapper\Factory\MappedAssetFactoryInterface;
use Symfony\Component\AssetMapper\ImportMap\RemotePackageStorage;
use Symfony\Component\Filesystem\Filesystem;

// Pages that load the import map need the scripts importmap:install downloads, which needs the network; the page only maps their paths, so empty stand-ins make it render.
trait DownloadedScriptStandIns
{
    private const array VENDOR_ASSETS = ['@hotwired/stimulus/stimulus.index.js', '@hotwired/turbo/turbo.index.js'];

    // Because the asset mapper finds the downloaded scripts through this service, replacing it moves them without changing the configuration of the test environment.
    private const string REMOTE_PACKAGE_STORAGE = 'asset_mapper.importmap.remote_package_storage';

    private const string ASSET_MAPPER = 'asset_mapper';

    private const string STAND_INS_NAMESPACE = 'downloaded_script_stand_ins';

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
        self::getContainer()->set(self::ASSET_MAPPER, $this->assetMapperThatAlsoMapsTheStandIns());
    }

    // The repository is the asset mapper's own, so only its folders change; its service cannot be replaced once read, so the mapper that holds it is.
    private function assetMapperThatAlsoMapsTheStandIns(): AssetMapper
    {
        $container = self::getContainer();
        $repository = $container->get('asset_mapper.repository');
        $factory = $container->get('asset_mapper.mapped_asset_factory');
        $configReader = $container->get('asset_mapper.compiled_asset_mapper_config_reader');
        self::assertInstanceOf(AssetMapperRepository::class, $repository);
        self::assertInstanceOf(MappedAssetFactoryInterface::class, $factory);
        self::assertInstanceOf(CompiledAssetMapperConfigReader::class, $configReader);
        $folders = [...$repository->allDirectories(), $this->standInFolder() => self::STAND_INS_NAMESPACE];

        return new AssetMapper(new AssetMapperRepository($folders, $container->getParameter('kernel.project_dir')), $factory, $configReader);
    }

    private function removeTheStandIns(): void
    {
        new Filesystem()->remove($this->standInFolder());
    }

    // Outside the folders every worker's asset mapper lists, since one worker removing its stand-ins between another's listing and loading fails that request; in TMPDIR, which each worker has of its own.
    private function standInFolder(): string
    {
        return sys_get_temp_dir().'/downloaded_script_stand_ins';
    }
}
