<?php

declare(strict_types=1);

namespace Intakt\Plugin\PhpLanguage\Domain;

use Intakt\Plugin\Language\Contract\DependencyFiles;
use JsonException;
use stdClass;

// A file the revision lacks reads as empty, because a project may have no lock file yet, or none any more.
final readonly class ComposerState
{
    public const string MANIFEST = 'composer.json';

    public const string LOCK = 'composer.lock';

    public const string REQUIRE = 'require';

    public const string REQUIRE_DEV = 'require-dev';

    // No settings, because Composer rewrites these on every update or derives them from the requirements and packages, which the package rules judge.
    private const array LOCK_RECORDS = ['_readme', 'content-hash', 'packages', 'packages-dev', 'stability-flags', 'platform', 'platform-dev', 'plugin-api-version'];

    /**
     * @param array<string, array{section: string, constraint: string}> $requirements
     * @param array<array-key, mixed>                                   $manifestSettings
     * @param array<array-key, mixed>                                   $lockSettings
     * @param array<string, mixed>                                      $stabilityFlags
     * @param array<string, LockedPackage>                              $locked
     */
    private function __construct(
        public array $requirements,
        public array $manifestSettings,
        public array $lockSettings,
        public array $stabilityFlags,
        public array $locked,
    ) {
    }

    /**
     * @throws ComposerFileUnreadable
     */
    public static function read(DependencyFiles $files): self
    {
        $manifest = self::decoded(self::MANIFEST, $files->of(self::MANIFEST));
        $lock = self::decoded(self::LOCK, $files->of(self::LOCK));

        return new self(
            self::once(self::MANIFEST, self::requirements($manifest, self::REQUIRE), self::requirements($manifest, self::REQUIRE_DEV)),
            array_diff_key($manifest, array_flip([self::REQUIRE, self::REQUIRE_DEV])),
            array_diff_key($lock, array_flip(self::LOCK_RECORDS)),
            self::stabilityFlags($lock),
            self::once(self::LOCK, self::lockedPackages($lock, PackageGroup::Main), self::lockedPackages($lock, PackageGroup::Development)),
        );
    }

    /**
     * @return list<string>
     */
    public function packagesAddedSince(self $before): array
    {
        return array_map(strval(...), array_keys(array_diff_key($this->requirements, $before->requirements) + array_diff_key($this->locked, $before->locked)));
    }

    /**
     * @return array<array-key, mixed>
     *
     * @throws ComposerFileUnreadable
     */
    private static function decoded(string $path, ?string $contents): array
    {
        if (null === $contents) {
            return [];
        }

        try {
            $object = json_decode($contents, flags: JSON_THROW_ON_ERROR);
            $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $invalid) {
            throw ComposerFileUnreadable::because($path, $invalid->getMessage());
        }

        // Decoded as an object first, because decoded into arrays an empty list and an empty object look the same.
        if (!$object instanceof stdClass || !is_array($decoded)) {
            throw ComposerFileUnreadable::because($path, 'it holds no JSON object');
        }

        return $decoded;
    }

    /**
     * @param array<array-key, mixed> $manifest
     *
     * @return array<string, array{section: string, constraint: string}>
     *
     * @throws ComposerFileUnreadable
     */
    private static function requirements(array $manifest, string $section): array
    {
        $requirements = [];
        foreach (self::map($manifest, $section, self::MANIFEST) as $name => $constraint) {
            if (!is_string($constraint)) {
                throw ComposerFileUnreadable::because(self::MANIFEST, sprintf('the constraint of %s in %s is no string', $name, $section));
            }

            $requirements[strtolower($name)] = ['section' => $section, 'constraint' => $constraint];
        }

        return $requirements;
    }

    /**
     * @template T
     *
     * @param array<string, T> $main
     * @param array<string, T> $development
     *
     * @return array<string, T>
     *
     * @throws ComposerFileUnreadable
     */
    private static function once(string $path, array $main, array $development): array
    {
        // A package in both groups would have one entry hide the other.
        if ([] !== array_intersect_key($main, $development)) {
            throw ComposerFileUnreadable::because($path, 'a package is in both the main and the development group');
        }

        return $main + $development;
    }

    /**
     * @param array<array-key, mixed> $lock
     *
     * @return array<string, mixed>
     *
     * @throws ComposerFileUnreadable
     */
    private static function stabilityFlags(array $lock): array
    {
        $flags = [];
        foreach (self::map($lock, 'stability-flags', self::LOCK) as $name => $flag) {
            $flags[strtolower($name)] = $flag;
        }

        return $flags;
    }

    /**
     * @param array<array-key, mixed> $lock
     *
     * @return array<string, LockedPackage>
     *
     * @throws ComposerFileUnreadable
     */
    private static function lockedPackages(array $lock, PackageGroup $group): array
    {
        $entries = $lock[$group->value] ?? [];

        if (!is_array($entries) || !array_is_list($entries)) {
            throw ComposerFileUnreadable::because(self::LOCK, sprintf('%s is no list', $group->value));
        }

        $packages = [];
        foreach ($entries as $entry) {
            $package = LockedPackage::fromLockEntry($entry, $group);
            $packages[$package->name] = $package;
        }

        // A name locked twice would let one entry hide the other.
        if (count($packages) !== count($entries)) {
            throw ComposerFileUnreadable::because(self::LOCK, sprintf('%s locks a package twice', $group->value));
        }

        return $packages;
    }

    /**
     * @param array<array-key, mixed> $file
     *
     * @return array<string, mixed>
     *
     * @throws ComposerFileUnreadable
     */
    private static function map(array $file, string $key, string $path): array
    {
        $map = $file[$key] ?? [];

        // A list only when empty, because Composer writes an empty map as [].
        if (!is_array($map) || ([] !== $map && array_is_list($map))) {
            throw ComposerFileUnreadable::because($path, sprintf('%s is no map of package names', $key));
        }

        $named = [];
        foreach ($map as $name => $value) {
            // Refused because PHP turns a numeric name into an integer key, which the lower-casing below cannot take, and no package is named by a number.
            if (!is_string($name)) {
                throw ComposerFileUnreadable::because($path, sprintf('%s names a package by a number', $key));
            }

            if (!PackageName::isReadable($name)) {
                throw ComposerFileUnreadable::because($path, sprintf('%s names "%s", which is no package name', $key, $name));
            }

            $named[$name] = $value;
        }

        // Composer reads names in any case, so two that differ only in case would let one hide the other.
        if (count(array_unique(array_map(strtolower(...), array_keys($named)))) !== count($named)) {
            throw ComposerFileUnreadable::because($path, sprintf('%s names a package twice', $key));
        }

        return $named;
    }
}
