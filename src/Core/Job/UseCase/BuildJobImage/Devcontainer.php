<?php

declare(strict_types=1);

namespace Intakt\Core\Job\UseCase\BuildJobImage;

use JsonException;
use Symfony\Component\Filesystem\Path;

final readonly class Devcontainer
{
    public const string PATH = '.devcontainer/devcontainer.json';
    private const string FOLDER = '.devcontainer';
    // A string first, so a comment marker or a comma inside a string is kept as it is.
    private const string COMMENT = '~("(?:[^"\\\\]|\\\\.)*")|//[^\n]*|/\*.*?\*/~s';
    private const string TRAILING_COMMA = '~("(?:[^"\\\\]|\\\\.)*")|,(?=\s*[}\]])~';

    /**
     * @throws JobImageNotBuilt
     */
    public static function planFrom(string $jsonc): JobImagePlan
    {
        $config = self::decoded($jsonc);

        if (array_key_exists('dockerComposeFile', $config)) {
            throw JobImageNotBuilt::composeUnsupported();
        }

        $build = $config['build'] ?? null;

        if (is_array($build) && array_key_exists('dockerfile', $build)) {
            return self::fromBuild($build);
        }

        $image = $config['image'] ?? null;

        return null === $image ? throw JobImageNotBuilt::withoutImageOrBuild() : self::fromImage(self::text($image, 'image'));
    }

    /**
     * @return array<mixed>
     */
    private static function decoded(string $jsonc): array
    {
        $keepStrings = static fn (array $match): string => is_string($match[1] ?? null) ? $match[1] : '';
        $json = preg_replace_callback(self::TRAILING_COMMA, $keepStrings, preg_replace_callback(self::COMMENT, $keepStrings, $jsonc) ?? '');

        try {
            $config = json_decode($json ?? '', true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $broken) {
            throw JobImageNotBuilt::notJson(self::PATH, $broken->getMessage());
        }

        return is_array($config) && ([] === $config || !array_is_list($config)) ? $config : throw JobImageNotBuilt::notJson(self::PATH, 'not an object');
    }

    /**
     * @param array<mixed> $build
     */
    private static function fromBuild(array $build): JobImagePlan
    {
        $context = self::text($build['context'] ?? '.', 'build.context');
        $dockerfile = self::text($build['dockerfile'], 'build.dockerfile');
        $tree = self::insideRepository($context);
        $path = Path::canonicalize(self::FOLDER.'/'.$dockerfile);

        if (Path::isAbsolute($dockerfile) || !self::isInside($path, $tree)) {
            throw JobImageNotBuilt::dockerfileOutsideContext($dockerfile);
        }

        return JobImagePlan::fromTree(JobImageSource::Devcontainer, $tree, '' === $tree ? $path : Path::makeRelative($path, $tree));
    }

    private static function insideRepository(string $context): string
    {
        $tree = Path::canonicalize(self::FOLDER.'/'.$context);

        if (Path::isAbsolute($context) || '..' === $tree || str_starts_with($tree, '../')) {
            throw JobImageNotBuilt::contextOutside($context);
        }

        return $tree;
    }

    private static function isInside(string $path, string $tree): bool
    {
        if ('..' === $path || str_starts_with($path, '../')) {
            return false;
        }

        return '' === $tree || ($path !== $tree && Path::isBasePath($tree, $path));
    }

    private static function text(mixed $value, string $key): string
    {
        return is_string($value) ? $value : throw JobImageNotBuilt::notText($key);
    }

    private static function fromImage(string $image): JobImagePlan
    {
        if (!ImageReference::isImage($image)) {
            throw JobImageNotBuilt::notAnImage($image);
        }

        return JobImagePlan::generated(JobImageSource::Devcontainer, sprintf("FROM %s\n", $image));
    }
}
