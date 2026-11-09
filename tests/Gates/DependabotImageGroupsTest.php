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

    /**
     * @return array<string, mixed>
     */
    private function dockerGroupOfEveryImage(): array
    {
        /** @var array{updates: list<array{'package-ecosystem': string, groups?: array<string, array<string, mixed>>}>} $configuration */
        $configuration = Yaml::parseFile(self::CONFIGURATION);

        foreach ($configuration['updates'] as $update) {
            foreach ('docker' === $update['package-ecosystem'] ? $update['groups'] ?? [] : [] as $group) {
                if (['*'] === ($group['patterns'] ?? null)) {
                    return $group;
                }
            }
        }

        self::fail('The docker entry of .github/dependabot.yml has no group with patterns [\'*\'].');
    }

    private function gitleaksImage(): string
    {
        self::assertSame(1, preg_match('#^FROM ([^\s:]+):\S+ AS gitleaks$#m', Workflow::ciImageDefinition(), $from), 'The CI image has no gitleaks stage.');

        return $from[1];
    }
}
