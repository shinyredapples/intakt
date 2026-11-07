<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Infrastructure;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

// Both images install what this script prints, so the extensions of composer.json stay the only list.
#[CoversNothing]
final class ProfileExtensionsTest extends TestCase
{
    private string $manifest;

    protected function setUp(): void
    {
        $this->manifest = sys_get_temp_dir().'/intakt-profile-'.bin2hex(random_bytes(6)).'.json';
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->manifest);
    }

    public function testItNamesARequiredExtensionThatIsNotLoaded(): void
    {
        $output = $this->missingFrom(['php' => '>=8.5', 'ext-intakt_absent' => '*']);

        self::assertSame("intakt_absent\n", $output);
    }

    public function testItLeavesOutARequiredExtensionThatIsAlreadyLoaded(): void
    {
        $output = $this->missingFrom(['ext-json' => '*', 'ext-Core' => '*']);

        self::assertSame("\n", $output);
    }

    public function testItRefusesAManifestWithoutARequireMap(): void
    {
        new Filesystem()->dumpFile($this->manifest, '{"require": "ext-intl"}');
        $process = new Process(['php', 'docker/profile-extensions.php', $this->manifest], dirname(__DIR__, 4));

        self::assertSame(1, $process->run());
    }

    /** @param array<string, string> $require */
    private function missingFrom(array $require): string
    {
        new Filesystem()->dumpFile($this->manifest, json_encode(['require' => $require], JSON_THROW_ON_ERROR));
        $process = new Process(['php', 'docker/profile-extensions.php', $this->manifest], dirname(__DIR__, 4));
        $process->mustRun();

        return $process->getOutput();
    }
}
