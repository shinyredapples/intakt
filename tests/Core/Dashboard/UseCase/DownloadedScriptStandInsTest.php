<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use LogicException;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Component\AssetMapper\AssetMapperInterface;
use Symfony\Component\Filesystem\Filesystem;

#[CoversNothing]
final class DownloadedScriptStandInsTest extends DashboardPageTestCase
{
    use DownloadedScriptStandIns;

    public function testTheAssetMapperOfTheTestFindsItsStandIns(): void
    {
        foreach (self::VENDOR_ASSETS as $asset) {
            self::assertNotNull($this->assetMapper()->getAssetFromSourcePath($this->standInFolder().'/'.$asset), $asset);
        }
    }

    // Two PHPUnit runs started by hand share the system temp directory, so a folder without the process in its name would let one remove the other's stand-ins.
    public function testTheStandInFolderBelongsToThisProcessAlone(): void
    {
        self::assertSame(self::STAND_INS_NAMESPACE.'_'.getmypid(), basename($this->standInFolder()));
    }

    // Another worker's asset mapper lists every file it maps before it loads them, so a stand-in it maps that this worker removes in between fails its request.
    public function testRemovingTheStandInsWhileAnotherWorkerListsItsAssetsBreaksNoListing(): void
    {
        self::bootKernel();

        $error = null;
        try {
            foreach ($this->assetMapper()->allAssets() as $index => $asset) {
                if (0 === $index) {
                    new Filesystem()->remove($this->standInFolder());
                }
            }
        } catch (LogicException $exception) {
            $error = $exception->getMessage();
        }

        self::assertNull($error);
    }

    private function assetMapper(): AssetMapperInterface
    {
        $mapper = self::getContainer()->get('asset_mapper');
        self::assertInstanceOf(AssetMapperInterface::class, $mapper);

        return $mapper;
    }
}
