<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

// A gitleaks update also needs GitleaksGate::PINNED_VERSION moved, so until then it fails the secrets gate and must not share a pull request with the other image updates.
#[CoversNothing]
final class DependabotImageGroupsTest extends TestCase
{
    private const string CONFIGURATION = __DIR__.'/../../.github/dependabot.yml';

    public function testTheGroupOfEveryImageLeavesTheGitleaksImageOut(): void
    {
        $group = $this->dockerGroupOfEveryImage();

        self::assertContains($this->gitleaksImage(), $group['exclude-patterns'] ?? []);
    }

    public function testTheGitleaksImageHasADockerGroupOfItsOwn(): void
    {
        /** @var array{updates: list<array{'package-ecosystem': string, groups?: array<string, array{patterns?: list<string>}>}>} $configuration */
        $configuration = Yaml::parseFile(self::CONFIGURATION);
        $docker = array_find($configuration['updates'], static fn (array $update): bool => 'docker' === $update['package-ecosystem']);
        $group = array_find($docker['groups'] ?? [], fn (array $group): bool => [$this->gitleaksImage()] === ($group['patterns'] ?? null));

        self::assertNotNull($group, 'The docker entry of .github/dependabot.yml has no group for the gitleaks image alone.');
    }

    /**
     * @return array{patterns?: list<string>, exclude-patterns?: list<string>}
     */
    private function dockerGroupOfEveryImage(): array
    {
        /** @var array{updates: list<array{'package-ecosystem': string, groups?: array<string, array{patterns?: list<string>, exclude-patterns?: list<string>}>}>} $configuration */
        $configuration = Yaml::parseFile(self::CONFIGURATION);
        $docker = array_find($configuration['updates'], static fn (array $update): bool => 'docker' === $update['package-ecosystem']);
        $group = array_find($docker['groups'] ?? [], static fn (array $group): bool => ['*'] === ($group['patterns'] ?? null));

        self::assertNotNull($group, 'The docker entry of .github/dependabot.yml has no group with patterns [\'*\'].');

        return $group;
    }

    private function gitleaksImage(): string
    {
        $from = [];

        self::assertSame(1, preg_match('#^FROM ([^\s:]+):\S+ AS gitleaks$#m', Workflow::ciImageDefinition(), $from), 'The CI image has no gitleaks stage.');

        return $from[1] ?? '';
    }
}
