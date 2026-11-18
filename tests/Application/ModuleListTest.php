<?php

declare(strict_types=1);

namespace Intakt\Tests\Application;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class ModuleListTest extends TestCase
{
    private const string ROOT = __DIR__.'/../..';

    public function testEveryModuleDirectoryIsInTheModuleList(): void
    {
        self::assertSame($this->moduleDirectories(), $this->moduleList());
    }

    public function testEveryModuleHasAContractNamespace(): void
    {
        foreach ($this->moduleList() as $module) {
            self::assertDirectoryExists(self::ROOT.'/src/'.$module.'/Contract', $module.' has no Contract namespace');
        }
    }

    /**
     * @return list<string>
     */
    private function moduleDirectories(): array
    {
        $directories = [];

        foreach (['Core', 'Plugin'] as $area) {
            $found = glob(self::ROOT.'/src/'.$area.'/*', GLOB_ONLYDIR);

            foreach (false === $found ? [] : $found as $directory) {
                $directories[] = $area.'/'.basename($directory);
            }
        }

        sort($directories);

        return $directories;
    }

    /**
     * @return list<string>
     */
    private function moduleList(): array
    {
        /** @var list<string> $modules */
        $modules = require self::ROOT.'/config/modules.php';
        sort($modules);

        return $modules;
    }
}
