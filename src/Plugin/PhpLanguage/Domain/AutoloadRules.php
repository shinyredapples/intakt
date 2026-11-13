<?php

declare(strict_types=1);

namespace Intakt\Plugin\PhpLanguage\Domain;

// A new package's autoloading, because the autoloader runs or loads whatever it names on the project's first class lookup.
final readonly class AutoloadRules
{
    private const string UNCHECKABLE = 'its autoload cannot be checked';

    private const string OUTSIDE = 'maps autoload outside its own namespace or folder';

    // Code by file, because a listed file runs on every request and a classmap can name any class.
    private const array CODE_LISTS = ['files' => 'has autoload files', 'classmap' => 'has an autoload classmap'];

    // Absolute, because a root, a drive or a stream wrapper reaches outside the package.
    private const string ABSOLUTE = '~\A(?:[/\\\\]|[A-Za-z]:|[A-Za-z][A-Za-z0-9+.-]*://)~';

    /**
     * @return list<string>
     */
    public function reasonsAgainst(LockedPackage $package): array
    {
        if (!is_array($package->autoload)) {
            return [self::UNCHECKABLE];
        }

        $reasons = [];
        foreach ($package->autoload as $kind => $rules) {
            $reasons[] = $this->reasonAgainst($package, (string) $kind, $rules);
        }

        return array_values(array_unique(array_filter($reasons, is_string(...))));
    }

    private function reasonAgainst(LockedPackage $package, string $kind, mixed $rules): ?string
    {
        return match ($kind) {
            // Allowed, because it only leaves files out.
            'exclude-from-classmap' => null,
            'files', 'classmap' => [] === $rules ? null : self::CODE_LISTS[$kind],
            'psr-4', 'psr-0' => $this->ownsEveryMapping($package, $rules) ? null : self::OUTSIDE,
            default => self::UNCHECKABLE,
        };
    }

    private function ownsEveryMapping(LockedPackage $package, mixed $mappings): bool
    {
        if (!is_array($mappings)) {
            return false;
        }

        return array_all($mappings, fn ($folders, $namespace) => $package->ownsNamespace((string) $namespace) && $this->insidePackage(is_array($folders) ? $folders : [$folders]));
    }

    /**
     * @param array<mixed> $folders
     */
    private function insidePackage(array $folders): bool
    {
        return [] !== $folders && [] === array_filter($folders, static fn (mixed $folder): bool => !self::isInsidePackage($folder));
    }

    // Relative and without a step up, because the autoloader would otherwise load files from the project or another package.
    private static function isInsidePackage(mixed $folder): bool
    {
        if (!is_string($folder) || 1 === preg_match(self::ABSOLUTE, $folder)) {
            return false;
        }

        $steps = preg_split('~[/\\\\]~', $folder);

        return is_array($steps) && !in_array('..', $steps, true);
    }
}
