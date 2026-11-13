<?php

declare(strict_types=1);

namespace Intakt\Plugin\PhpLanguage\Domain;

// The owner's rules for which new packages are routine: their vendors, and the package types those vendors may add.
final readonly class TrustedPackages
{
    /**
     * @param list<string> $vendors
     * @param list<string> $types
     */
    public function __construct(
        private array $vendors,
        private array $types,
    ) {
    }

    // The vendor is the whole part before the slash, so "symfonyx/" is not "symfony/", and a platform requirement such as "ext-intl" has none.
    public function trusts(string $name): bool
    {
        return str_contains($name, '/') && in_array(strtolower(explode('/', $name, 2)[0]), $this->vendors, true);
    }

    public function allowsType(string $type): bool
    {
        return in_array($type, $this->types, true);
    }
}
