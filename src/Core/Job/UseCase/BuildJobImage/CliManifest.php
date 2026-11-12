<?php

declare(strict_types=1);

namespace Intakt\Core\Job\UseCase\BuildJobImage;

use JsonException;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;

final readonly class CliManifest
{
    // The one home of the Claude Code and Codex versions, so a Dependabot pull request that bumps them is all a bump takes.
    public const string PATH = __DIR__.'/../../../../../docker/job/cli/package.json';
    private const string PACKAGE = '#^(?:@[a-z0-9][a-z0-9._-]*/)?[a-z0-9][a-z0-9._-]*$#';
    private const string EXACT_VERSION = '/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.]+)?$/';

    /**
     * @return list<string>
     *
     * @throws JobImageNotBuilt
     */
    public static function installArgumentsFrom(string $manifest): array
    {
        $dependencies = self::dependenciesIn($manifest, self::read($manifest));
        $arguments = [];

        foreach ($dependencies as $package => $version) {
            $arguments[] = self::installArgument($manifest, $package, $version);
        }

        return $arguments;
    }

    private static function read(string $manifest): string
    {
        try {
            return new Filesystem()->readFile($manifest);
        } catch (IOException) {
            throw JobImageNotBuilt::unusableCliManifest($manifest, 'it cannot be read');
        }
    }

    /**
     * @return non-empty-array<mixed>
     */
    private static function dependenciesIn(string $manifest, string $json): array
    {
        try {
            $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw JobImageNotBuilt::unusableCliManifest($manifest, 'it is not valid JSON: '.$exception->getMessage());
        }

        $dependencies = is_array($decoded) ? $decoded['dependencies'] ?? null : null;

        return is_array($dependencies) && [] !== $dependencies ? $dependencies : throw JobImageNotBuilt::unusableCliManifest($manifest, 'it names no dependencies');
    }

    // Both parts end up in a RUN line, so only an npm package name and an exact version pass: no range, tag, URL or shell word.
    private static function installArgument(string $manifest, int|string $package, mixed $version): string
    {
        if (!is_string($package) || 1 !== preg_match(self::PACKAGE, $package)) {
            throw JobImageNotBuilt::unusableCliManifest($manifest, sprintf('"%s" is not an npm package name', $package));
        }

        if (!is_string($version) || 1 !== preg_match(self::EXACT_VERSION, $version)) {
            throw JobImageNotBuilt::unusableCliManifest($manifest, sprintf('%s has no exact version', $package));
        }

        return $package.'@'.$version;
    }
}
