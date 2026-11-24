<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Watcher\Infrastructure;

use Intakt\Core\Watcher\Infrastructure\WatchConfiguration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

#[CoversClass(WatchConfiguration::class)]
final class WatchConfigurationTest extends TestCase
{
    public function testAWatchSectionWithOnlyTheRequiredKeysIsAccepted(): void
    {
        $projects = $this->processed(['repository' => '/r', 'base' => 'main', 'task' => 'Review.']);

        self::assertSame(['alpha' => ['repository' => '/r', 'base' => 'main', 'task' => 'Review.', 'eligibility_label' => 'intakt']], $projects);
    }

    public function testAGitHubRepositoryNamedOwnerSlashNameIsAccepted(): void
    {
        $projects = $this->processed(['repository' => '/r', 'base' => 'main', 'task' => 'Review.', 'github' => 'acme/widgets']);

        self::assertSame(['alpha' => ['repository' => '/r', 'base' => 'main', 'task' => 'Review.', 'github' => 'acme/widgets', 'eligibility_label' => 'intakt']], $projects);
    }

    public function testANamedEligibilityLabelReplacesTheDefault(): void
    {
        $projects = $this->processed(['repository' => '/r', 'base' => 'main', 'task' => 'Review.', 'eligibility_label' => 'build']);

        self::assertSame(['alpha' => ['repository' => '/r', 'base' => 'main', 'task' => 'Review.', 'eligibility_label' => 'build']], $projects);
    }

    public function testABlankEligibilityLabelIsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->processed(['repository' => '/r', 'base' => 'main', 'task' => 'Review.', 'eligibility_label' => '']);
    }

    public function testAWhitespaceEligibilityLabelIsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->processed(['repository' => '/r', 'base' => 'main', 'task' => 'Review.', 'eligibility_label' => " \u{00A0}\t"]);
    }

    public function testAnEligibilityLabelOfOnlyANoBreakSpaceIsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/"eligibility_label" key must not be blank/');

        $this->processed(['repository' => '/r', 'base' => 'main', 'task' => 'Review.', 'eligibility_label' => "\u{00A0}"]);
    }

    public function testAnEligibilityLabelWithSpacesInsideIsAccepted(): void
    {
        $projects = $this->processed(['repository' => '/r', 'base' => 'main', 'task' => 'Review.', 'eligibility_label' => 'ready to build']);

        self::assertSame(['alpha' => ['repository' => '/r', 'base' => 'main', 'task' => 'Review.', 'eligibility_label' => 'ready to build']], $projects);
    }

    #[DataProvider('misspelledRepositories')]
    public function testAGitHubRepositoryThatIsNotOwnerSlashNameIsRefused(string $name): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/The "github" key must be owner\/name\./');

        $this->processed(['repository' => '/r', 'base' => 'main', 'task' => 'Review.', 'github' => $name]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function misspelledRepositories(): iterable
    {
        yield 'no owner' => ['widgets'];
        yield 'an address' => ['https://github.com/acme/widgets'];
        yield 'a third part' => ['acme/widgets/pull'];
        yield 'dots only' => ['acme/..'];
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

    public function testAMissingTaskIsRefusedNamingItsProject(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/"task" under "intakt\.projects\.alpha" must be configured/');

        $this->processed(['repository' => '/r', 'base' => 'main']);
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
