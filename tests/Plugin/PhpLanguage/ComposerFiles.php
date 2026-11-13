<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\PhpLanguage;

use Intakt\Plugin\Language\Contract\DependencyFiles;

// A composer.json and composer.lock pair built in steps, so each test states only what its change does.
final readonly class ComposerFiles
{
    /**
     * @param array<string, mixed> $manifest
     * @param array<string, mixed> $lock
     */
    private function __construct(
        private array $manifest,
        private array $lock,
    ) {
    }

    public static function none(): self
    {
        return new self([], []);
    }

    // A project that already needs symfony/console, locked at its upstream tag, as composer writes it.
    public static function sample(): self
    {
        return new self(
            ['name' => 'acme/app', 'type' => 'project', 'require' => ['php' => '>=8.5', 'symfony/console' => '^8.0'], 'require-dev' => [], 'config' => ['sort-packages' => true]],
            ['_readme' => ['generated'], 'content-hash' => 'aaa', 'packages' => [self::entry('symfony/console', 'v8.0.0', LockedCommits::CONSOLE_8_0_0)], 'packages-dev' => [], 'aliases' => [], 'minimum-stability' => 'stable', 'stability-flags' => [], 'prefer-stable' => true, 'prefer-lowest' => false, 'platform' => ['php' => '>=8.5'], 'platform-dev' => [], 'plugin-api-version' => '2.9.0'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public static function entry(string $name, string $version, string $reference): array
    {
        return self::inRepository([
            'name' => $name,
            'version' => $version,
            'source' => ['type' => 'git', 'reference' => $reference],
            'dist' => ['type' => 'zip', 'reference' => $reference, 'shasum' => ''],
            'type' => 'library',
            'autoload' => ['psr-4' => ['Vendor\\' => 'src/']],
            'notification-url' => 'https://packagist.org/downloads/',
        ], $name);
    }

    /**
     * @param array<string, mixed> $entry
     *
     * @return array<string, mixed>
     */
    public static function inRepository(array $entry, string $repository): array
    {
        $source = is_array($entry['source'] ?? null) ? $entry['source'] : [];
        $dist = is_array($entry['dist'] ?? null) ? $entry['dist'] : [];
        $reference = is_string($source['reference'] ?? null) ? $source['reference'] : '';

        return [
            ...$entry,
            'source' => [...$source, 'url' => 'https://github.com/'.$repository.'.git'],
            'dist' => [...$dist, 'url' => 'https://api.github.com/repos/'.$repository.'/zipball/'.$reference],
        ];
    }

    public function requiring(string $name, string $constraint): self
    {
        return $this->withRequirement('require', $name, $constraint);
    }

    public function requiringForDevelopment(string $name, string $constraint): self
    {
        return $this->withRequirement('require-dev', $name, $constraint);
    }

    public function withoutRequirement(string $name): self
    {
        $manifest = $this->manifest;
        foreach (['require', 'require-dev'] as $section) {
            if (is_array($manifest[$section] ?? null)) {
                unset($manifest[$section][$name]);
            }
        }

        return new self($manifest, $this->lock);
    }

    /**
     * @param array<string, mixed> $entry
     */
    public function locking(array $entry): self
    {
        return $this->withLocked('packages', $entry);
    }

    /**
     * @param array<string, mixed> $entry
     */
    public function lockingForDevelopment(array $entry): self
    {
        return $this->withLocked('packages-dev', $entry);
    }

    public function withManifestKey(string $key, mixed $value): self
    {
        return new self([...$this->manifest, $key => $value], $this->lock);
    }

    public function withLockKey(string $key, mixed $value): self
    {
        return new self($this->manifest, [...$this->lock, $key => $value]);
    }

    public function files(): DependencyFiles
    {
        return new DependencyFiles(array_filter([
            'composer.json' => [] === $this->manifest ? null : json_encode($this->manifest, JSON_THROW_ON_ERROR),
            'composer.lock' => [] === $this->lock ? null : json_encode($this->lock, JSON_THROW_ON_ERROR),
        ], is_string(...)));
    }

    private function withRequirement(string $section, string $name, string $constraint): self
    {
        $manifest = $this->withoutRequirement($name)->manifest;
        $requirements = is_array($manifest[$section] ?? null) ? $manifest[$section] : [];

        return new self([...$manifest, $section => [...$requirements, $name => $constraint]], $this->lock);
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function withLocked(string $group, array $entry): self
    {
        $lock = $this->lock;
        $kept = [];
        foreach (['packages', 'packages-dev'] as $each) {
            $entries = is_array($lock[$each] ?? null) ? $lock[$each] : [];
            $kept[$each] = array_values(array_filter($entries, static fn (mixed $locked): bool => !is_array($locked) || ($locked['name'] ?? null) !== $entry['name']));
        }

        $lock = [...$lock, ...$kept, $group => [...$kept[$group] ?? [], $entry]];

        return new self($this->manifest, $lock);
    }
}
