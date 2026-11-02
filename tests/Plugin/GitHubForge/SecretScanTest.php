<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubForge;

use Intakt\Plugin\Forge\Contract\ForgeWriteFailed;
use Intakt\Plugin\GitHubForge\Infrastructure\SecretScan;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SecretScan::class)]
#[UsesClass(ForgeWriteFailed::class)]
final class SecretScanTest extends TestCase
{
    private const string NO_RULES = "title = \"no rules\"\n";

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
        // Process passes on only the variables $_SERVER lists, so the test sets both.
        putenv('GITLEAKS_CONFIG_TOML='.self::NO_RULES);
        $_SERVER['GITLEAKS_CONFIG_TOML'] = self::NO_RULES;

        try {
            $found = $this->scan()->findsSecretIn(AppKey::generate()->private);
        } finally {
            putenv('GITLEAKS_CONFIG_TOML');
            unset($_SERVER['GITLEAKS_CONFIG_TOML']);
        }

        self::assertTrue($found);
    }

    public function testItFailsTheWriteWhenTheScannerIsMissing(): void
    {
        $this->expectException(ForgeWriteFailed::class);

        $found = new SecretScan(dirname(__DIR__, 3), 'intakt-missing-scanner')->findsSecretIn('Two reviewers approve.');
        self::fail('A missing scanner must fail the write, not answer '.var_export($found, true).'.');
    }

    private function scan(): SecretScan
    {
        return new SecretScan(dirname(__DIR__, 3));
    }
}
