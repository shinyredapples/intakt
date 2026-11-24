<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\ReviewerForLoop\Infrastructure;

use Intakt\Core\ReviewerForLoop\Contract\ProjectMode;
use Intakt\Core\ReviewerForLoop\Infrastructure\ProjectModeConfiguration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

#[CoversClass(ProjectModeConfiguration::class)]
#[UsesClass(ProjectMode::class)]
final class ProjectModeConfigurationTest extends TestCase
{
    public function testAKnownModeIsKeptForItsProject(): void
    {
        self::assertSame(['beta' => ['mode' => ProjectMode::ReviewerForLoop->value]], $this->processed(ProjectMode::ReviewerForLoop->value));
    }

    public function testAnUnknownModeIsRefusedNamingItsPath(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/"quiet" is not allowed for path "intakt\.projects\.beta\.mode"/');

        $this->processed('quiet');
    }

    /**
     * @return array<string, mixed>
     */
    private function processed(string $mode): array
    {
        /** @var array<string, mixed> $projects */
        $projects = new Processor()->processConfiguration(new ProjectModeConfiguration(), [['projects' => ['beta' => ['mode' => $mode]]]])['projects'];

        return $projects;
    }
}
