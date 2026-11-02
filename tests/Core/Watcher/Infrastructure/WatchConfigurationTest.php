<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Watcher\Infrastructure;

use Intakt\Core\Watcher\Infrastructure\WatchConfiguration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

#[CoversClass(WatchConfiguration::class)]
final class WatchConfigurationTest extends TestCase
{
    public function testAWatchSectionWithEveryKeyIsAccepted(): void
    {
        $projects = $this->processed(['repository' => '/r', 'base' => 'main', 'task' => 'Review.']);

        self::assertSame(['alpha' => ['repository' => '/r', 'base' => 'main', 'task' => 'Review.']], $projects);
    }

    public function testABlankTaskIsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/The "task" key must not be blank\./');

        $this->processed(['repository' => '/r', 'base' => 'main', 'task' => '   ']);
    }

    public function testANumericRepositoryIsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->processed(['repository' => 5, 'base' => 'main', 'task' => 'Review.']);
    }

    public function testAMissingBaseIsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->processed(['repository' => '/r', 'task' => 'Review.']);
    }

    /**
     * @param array<string, mixed> $watch
     *
     * @return array<string, mixed>
     */
    private function processed(array $watch): array
    {
        /** @var array<string, mixed> $projects */
        $projects = new Processor()->processConfiguration(new WatchConfiguration(), [['projects' => ['alpha' => $watch]]])['projects'];

        return $projects;
    }
}
