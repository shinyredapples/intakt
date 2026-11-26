<?php

declare(strict_types=1);

namespace Intakt\Tests\Application;

use Intakt\Core\Review\Contract\ReviewServices;
use Intakt\Core\Review\Infrastructure\ReviewSizeLimitConfiguration;
use Intakt\Tests\Support\CompiledContainers;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Config\Exception\LoaderLoadException;
use Symfony\Component\Filesystem\Filesystem;

// The limit as the container wires it from the config file, because the key is read out of the project in config/packages/ai.php.
#[CoversClass(ReviewSizeLimitConfiguration::class)]
final class WiredReviewSizeLimitTest extends KernelTestCase
{
    private string $file = '';

    private mixed $savedServer = null;

    private CompiledContainers $containers;

    public static function tearDownAfterClass(): void
    {
        self::compiledContainers()->remove();
        parent::tearDownAfterClass();
    }

    public function testAProjectWithoutTheSettingBootsWithTodaysLimit(): void
    {
        self::assertSame(['alpha' => 200000], $this->limitsFor(''));
    }

    public function testAProjectWithItsOwnLimitBootsWithThatLimit(): void
    {
        self::assertSame(['alpha' => 350000], $this->limitsFor('        review_size_limit: 350000'));
    }

    public function testAnInvalidLimitStopsBootWithAPlainMessage(): void
    {
        $this->expectException(LoaderLoadException::class);
        $this->expectExceptionMessageMatches('/must be a positive whole number of characters of diff/');

        $this->limitsFor('        review_size_limit: 0');
    }

    public function testANonNumberLimitStopsBootWithAPlainMessage(): void
    {
        $this->expectException(LoaderLoadException::class);
        $this->expectExceptionMessageMatches('/must be a positive whole number of characters of diff/');

        $this->limitsFor('        review_size_limit: abc');
    }

    public function testAProjectDeclaredAsANamedListEntryKeepsItsOwnLimit(): void
    {
        $yaml = "projects:\n    - name: alpha\n      tiers:\n          strong: { provider: anthropic, model: big }\n      review_size_limit: 41\n";

        self::assertSame(['alpha' => 41], $this->limitsOf($yaml));
    }

    protected function setUp(): void
    {
        $this->containers = self::compiledContainers();
        $this->savedServer = $_SERVER['INTAKT_CONFIG'] ?? null;
        $this->file = sys_get_temp_dir().'/intakt-size-limit-'.bin2hex(random_bytes(4)).'.yaml';
    }

    protected function tearDown(): void
    {
        unset($_SERVER['INTAKT_CONFIG']);
        if (null !== $this->savedServer) {
            $_SERVER['INTAKT_CONFIG'] = $this->savedServer;
        }

        parent::tearDown();
        $this->containers->restoreTheSharedCache();
        new Filesystem()->remove($this->file);
    }

    /**
     * @return array<string, int>
     */
    private function limitsFor(string $line): array
    {
        $yaml = "projects:\n    alpha:\n        tiers:\n            strong: { provider: anthropic, model: big }\n".('' === $line ? '' : $line."\n");

        return $this->limitsOf($yaml);
    }

    /**
     * @return array<string, int>
     */
    private function limitsOf(string $yaml): array
    {
        file_put_contents($this->file, $yaml);
        $_SERVER['INTAKT_CONFIG'] = $this->file;
        self::ensureKernelShutdown();
        $this->containers->useFor([$yaml]);
        self::bootKernel();

        /** @var array<string, int> $limits */
        $limits = self::getContainer()->getParameter(ReviewServices::SIZE_LIMITS);

        return $limits;
    }

    private static function compiledContainers(): CompiledContainers
    {
        return CompiledContainers::beside(self::createKernel()->getCacheDir(), 'review-size-limit');
    }
}
