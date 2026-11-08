<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use Override;

// Pages that load the import map need the scripts importmap:install downloads, which needs the network; the page only maps their paths, so empty stand-ins make it render.
trait DownloadedScriptStandIns
{
    private const array VENDOR_ASSETS = ['@hotwired/stimulus/stimulus.index.js', '@hotwired/turbo/turbo.index.js'];

    /** @var list<string> */
    private array $placeholders = [];

    #[Override]
    protected function setUp(): void
    {
        $this->givenTheDownloadedScriptsExist();
        parent::setUp();
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
            $path = dirname(__DIR__, 4).'/assets/vendor/'.$asset;
            if (is_file($path)) {
                continue;
            }

            if (!is_dir(dirname($path))) {
                mkdir(dirname($path), 0o777, true);
            }

            file_put_contents($path, '');
            $this->placeholders[] = $path;
        }
    }

    private function removeTheStandIns(): void
    {
        array_map(unlink(...), $this->placeholders);
    }
}
