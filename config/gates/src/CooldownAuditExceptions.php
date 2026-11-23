<?php

declare(strict_types=1);

namespace Intakt\Gates;

use JsonException;

// Because the audit note must name what Composer really applies, this reads the shapes of Composer's IgnorePackageRule::parseIgnoreMap; Composer refuses any other shape itself.
final readonly class CooldownAuditExceptions
{
    private const string EVERY_VERSION = ' (every version)';

    /**
     * @return list<string>
     */
    public function in(string $root): array
    {
        $exceptions = [];

        foreach ($this->ignoreMap($root.'/composer.json') as $key => $value) {
            $exceptions = [...$exceptions, ...(is_int($key) ? $this->listed($value) : $this->forPackage($key, $value))];
        }

        return $exceptions;
    }

    /**
     * @return array<mixed>
     */
    private function ignoreMap(string $path): array
    {
        try {
            $node = is_file($path) ? json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR) : null;
        } catch (JsonException) {
            return [];
        }

        foreach (['config', 'policy', 'cooldown', 'ignore'] as $key) {
            $node = is_array($node) ? $node[$key] ?? null : null;
        }

        return is_array($node) ? $node : [];
    }

    /**
     * @return list<string>
     */
    private function listed(mixed $value): array
    {
        return is_string($value) ? [$value.self::EVERY_VERSION] : [];
    }

    /**
     * @return list<string>
     */
    private function forPackage(string $package, mixed $value): array
    {
        if (null === $value || is_string($value)) {
            return [$package.self::EVERY_VERSION];
        }

        $exceptions = [];

        foreach (is_array($value) && isset($value[0]) ? $value : [$value] as $rule) {
            $exceptions = [...$exceptions, ...$this->ofRule($package, $rule)];
        }

        return $exceptions;
    }

    /**
     * @return list<string>
     */
    private function ofRule(string $package, mixed $rule): array
    {
        if (!is_array($rule) || false === ($rule['on-audit'] ?? true)) {
            return [];
        }

        $constraint = $rule['constraint'] ?? null;

        return [is_scalar($constraint) && '' !== (string) $constraint ? $package.' '.$constraint : $package.self::EVERY_VERSION];
    }
}
