<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubForge;

use Intakt\Kernel;
use Intakt\Plugin\Forge\Contract\ForgeWriteFailed;
use Intakt\Plugin\GitHubForge\Infrastructure\SecretScan;
use Intakt\Tests\Support\KernelTestCase;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(SecretScan::class)]
#[UsesClass(ForgeWriteFailed::class)]
#[UsesClass(Kernel::class)]
final class SecretScanTest extends KernelTestCase
{
    private const string NO_RULES = "title = \"no rules\"\n";
    private const string SCANNER_ENVIRONMENT = 'INTAKT_GITLEAKS';
    private const string CONFIGURED_SCAN = 'intakt.test.secret_scan';

    private string|false $callerScanner = false;

    public function testItFindsAPrivateKeyInTheText(): void
    {
        self::assertTrue($this->scan()->findsSecretIn("The fixture holds\n".AppKey::generate()->private));
    }

    public function testItFindsNothingInAPlainReview(): void
    {
        self::assertFalse($this->scan()->findsSecretIn('Two reviewers approve; the head is 1111111111111111111111111111111111111111.'));
    }

    public function testItIgnoresAnAllowCommentTheJobWroteIntoTheText(): void
    {
        $lines = explode("\n", trim(AppKey::generate()->private));
        $lines[1] .= " # gitleaks\x3aallow";

        self::assertTrue($this->scan()->findsSecretIn(implode("\n", $lines)));
    }

    public function testItKeepsTheBuiltInRulesWhenTheEnvironmentNamesAnotherConfig(): void
    {
        $this->setEnvironment('GITLEAKS_CONFIG_TOML', self::NO_RULES);

        try {
            $found = $this->scan()->findsSecretIn(AppKey::generate()->private);
        } finally {
            $this->unsetEnvironment('GITLEAKS_CONFIG_TOML');
        }

        self::assertTrue($found);
    }

    public function testItRunsGitleaksFromThePathWhenNoScannerIsConfigured(): void
    {
        self::assertTrue($this->configuredScan()->findsSecretIn(AppKey::generate()->private));
    }

    public function testItRunsGitleaksFromThePathWhenTheConfiguredScannerIsEmpty(): void
    {
        $this->setEnvironment(self::SCANNER_ENVIRONMENT, '');

        self::assertTrue($this->configuredScan()->findsSecretIn(AppKey::generate()->private));
    }

    public function testItFailsTheWriteWhenTheConfiguredScannerIsMissing(): void
    {
        $this->setEnvironment(self::SCANNER_ENVIRONMENT, 'intakt-missing-scanner');
        $this->expectException(ForgeWriteFailed::class);

        $found = $this->configuredScan()->findsSecretIn('Two reviewers approve.');
        self::fail('A missing scanner must fail the write, not answer '.var_export($found, true).'.');
    }

    // The caller may point INTAKT_GITLEAKS at its scanner, so each test starts without it and gives it back.
    #[Override]
    protected function setUp(): void
    {
        $this->callerScanner = getenv(self::SCANNER_ENVIRONMENT);
        $this->unsetEnvironment(self::SCANNER_ENVIRONMENT);
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->unsetEnvironment(self::SCANNER_ENVIRONMENT);

        if (false !== $this->callerScanner) {
            $this->setEnvironment(self::SCANNER_ENVIRONMENT, $this->callerScanner);
        }

        parent::tearDown();
    }

    private function scan(): SecretScan
    {
        return new SecretScan(dirname(__DIR__, 3), 'gitleaks');
    }

    private function configuredScan(): SecretScan
    {
        $scan = self::getContainer()->get(self::CONFIGURED_SCAN);
        self::assertInstanceOf(SecretScan::class, $scan);

        return $scan;
    }

    // Process passes on only the variables $_SERVER lists, and the container reads $_SERVER first, so both are set.
    private function setEnvironment(string $name, string $value): void
    {
        putenv($name.'='.$value);
        $_SERVER[$name] = $value;
    }

    private function unsetEnvironment(string $name): void
    {
        putenv($name);
        unset($_SERVER[$name]);
    }
}
