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
    private const string JOB_IMAGE_CLI_MANIFEST = __DIR__.'/../../docker/job/cli/package.json';

    public function testTheGroupOfEveryImageLeavesTheGitleaksImageOut(): void
    {
        $group = $this->dockerGroupOfEveryImage();

        self::assertSame([$this->gitleaksImage()], $group['exclude-patterns'] ?? null, 'The exclude-patterns of the docker group with patterns [\'*\'] in .github/dependabot.yml must hold the gitleaks image and nothing else.');
    }

    public function testTheGitleaksImageHasADockerGroupOfItsOwn(): void
    {
        /** @var array{updates: list<array{'package-ecosystem': string, groups?: array<string, array{patterns?: list<string>}>}>} $configuration */
        $configuration = Yaml::parseFile(self::CONFIGURATION);
        $docker = array_find($configuration['updates'], static fn (array $update): bool => 'docker' === $update['package-ecosystem']);
        $group = array_find($docker['groups'] ?? [], fn (array $group): bool => [$this->gitleaksImage()] === ($group['patterns'] ?? null));

        self::assertNotNull($group, 'The docker entry of .github/dependabot.yml has no group for the gitleaks image alone.');
    }

    public function testTheJobImageCliManifestHasAnNpmEntry(): void
    {
        self::assertNotNull($this->jobImageCliEntry(), 'The npm entry of .github/dependabot.yml for /docker/job/cli is missing.');
    }

    // One group for both CLIs, so one update round rebuilds the job image once.
    public function testBothJobImageClisFallIntoOneGroup(): void
    {
        /** @var array{dependencies: array<string, string>} $manifest */
        $manifest = json_decode((string) file_get_contents(self::JOB_IMAGE_CLI_MANIFEST), true, flags: JSON_THROW_ON_ERROR);
        $clis = array_keys($manifest['dependencies']);
        $groups = $this->jobImageCliEntry()['groups'] ?? [];
        $groupsOfEveryCli = array_filter($groups, static fn (array $group): bool => array_all($clis, static fn (string $cli): bool => array_any($group['patterns'] ?? [], static fn (string $pattern): bool => fnmatch($pattern, $cli))));

        self::assertCount(2, $clis, 'docker/job/cli/package.json must list Claude Code and Codex.');
        self::assertCount(1, $groups, 'The npm entry for /docker/job/cli must hold exactly one group.');
        self::assertCount(1, $groupsOfEveryCli, 'The one group of the npm entry for /docker/job/cli must match both CLIs.');
    }

    /**
     * @return array{groups?: array<string, array{patterns?: list<string>}>}|null
     */
    private function jobImageCliEntry(): ?array
    {
        /** @var array{updates: list<array{'package-ecosystem': string, directory?: string, groups?: array<string, array{patterns?: list<string>}>}>} $configuration */
        $configuration = Yaml::parseFile(self::CONFIGURATION);

        return array_find($configuration['updates'], static fn (array $update): bool => 'npm' === $update['package-ecosystem'] && '/docker/job/cli' === ($update['directory'] ?? null));
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
