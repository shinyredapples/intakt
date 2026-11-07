<?php

declare(strict_types=1);

// The images install what this prints, so the ext-* entries of composer.json stay the one list of extensions.
$manifest = json_decode((string) file_get_contents($argv[1] ?? 'composer.json'), true, flags: JSON_THROW_ON_ERROR);
$require = is_array($manifest) ? $manifest['require'] ?? null : null;
if (!is_array($require)) {
    fwrite(STDERR, "no require map in the manifest\n");

    exit(1);
}

echo array_keys($require)
    |> (static fn (array $packages): array => array_filter($packages, static fn (int|string $package): bool => str_starts_with((string) $package, 'ext-')))
    |> (static fn (array $packages): array => array_map(static fn (int|string $package): string => substr((string) $package, 4), $packages))
    |> (static fn (array $extensions): array => array_filter($extensions, static fn (string $extension): bool => !extension_loaded($extension)))
    |> (static fn (array $extensions): string => implode(' ', $extensions)), "\n";
