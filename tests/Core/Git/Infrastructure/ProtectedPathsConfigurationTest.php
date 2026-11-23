<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Git\Infrastructure;

use Intakt\Core\Git\Domain\ProtectedPathRefused;
use Intakt\Core\Git\Domain\ProtectedPaths;
use Intakt\Core\Git\Infrastructure\ProtectedPathsConfiguration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

#[CoversClass(ProtectedPathsConfiguration::class)]
#[UsesClass(ProtectedPaths::class)]
#[UsesClass(ProtectedPathRefused::class)]
final class ProtectedPathsConfigurationTest extends TestCase
{
    public function testAListOfFilesAndFoldersIsAccepted(): void
    {
        $projects = $this->processed(['protected_paths' => ['bin/check', 'config/gates/', '.github']]);

        self::assertSame(['alpha' => ['protected_paths' => ['bin/check', 'config/gates/', '.github']]], $projects);
    }

    public function testAProjectWithoutAListProtectsNoListedPath(): void
    {
        self::assertSame(['alpha' => ['protected_paths' => []]], $this->processed([]));
    }

    public function testAMalformedEntryIsRefusedAtBoot(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageIsOrContains('"/bin/check" is not a protected path');

        $this->processed(['protected_paths' => ['bin/check', '/bin/check']]);
    }

    public function testAnEntryThatIsNoStringIsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->processed(['protected_paths' => [['bin/check']]]);
    }

    public function testAnUnknownKeyIsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->processed(['protected' => ['bin/check']]);
    }

    /**
     * @param array<string, mixed> $project
     *
     * @return array<string, mixed>
     */
    private function processed(array $project): array
    {
        /** @var array{projects: array<string, mixed>} $configuration */
        $configuration = new Processor()->processConfiguration(new ProtectedPathsConfiguration(), [['projects' => ['alpha' => $project]]]);

        return $configuration['projects'];
    }
}
