<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\PhpLanguage\Infrastructure;

use Intakt\Plugin\PhpLanguage\Infrastructure\ComposerPolicyConfiguration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

#[CoversClass(ComposerPolicyConfiguration::class)]
final class ComposerPolicyConfigurationTest extends TestCase
{
    public function testWithoutASectionSymfonyAndDoctrineAreTrustedWithLibrariesBundlesAndSymfonyAiComponents(): void
    {
        self::assertSame(['composer_policy' => [
            'trusted_vendors' => ['symfony', 'doctrine'],
            'package_types' => ['library', 'symfony-bundle', 'symfony-ai-platform', 'symfony-ai-store', 'symfony-ai-message-store', 'symfony-ai-tool', 'symfony-ai-mate'],
        ]], $this->processed([]));
    }

    public function testTheOwnersListsReplaceTheDefaults(): void
    {
        $processed = $this->processed(['composer_policy' => ['trusted_vendors' => ['symfony'], 'package_types' => ['library']]]);

        self::assertSame(['composer_policy' => ['trusted_vendors' => ['symfony'], 'package_types' => ['library']]], $processed);
    }

    public function testAnEmptyListOfVendorsTrustsNone(): void
    {
        self::assertSame([], $this->processed(['composer_policy' => ['trusted_vendors' => []]])['composer_policy']['trusted_vendors']);
    }

    public function testAVendorWrittenWithItsSlashIsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageIsOrContains('trusted_vendors.0": "symfony\/" is not a vendor name such as "symfony"');

        $this->processed(['composer_policy' => ['trusted_vendors' => ['symfony/']]]);
    }

    public function testAVendorInCapitalsIsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->processed(['composer_policy' => ['trusted_vendors' => ['Symfony']]]);
    }

    /**
     * @param array<string, mixed> $section
     *
     * @return array{composer_policy: array{trusted_vendors: list<string>, package_types: list<string>}}
     */
    private function processed(array $section): array
    {
        /** @var array{composer_policy: array{trusted_vendors: list<string>, package_types: list<string>}} */
        return new Processor()->processConfiguration(new ComposerPolicyConfiguration(), [$section]);
    }
}
