<?php

declare(strict_types=1);

namespace Intakt\Core\Job\UseCase\BuildJobImage;

use Intakt\Core\Job\Contract\JobEnd;
use Intakt\Core\Platform\Contract\ReportableFailure;
use RuntimeException;

final class JobImageNotBuilt extends RuntimeException implements ReportableFailure
{
    private const int OUTPUT_LINES = 20;

    public static function withoutSource(): self
    {
        return new self(sprintf('The project has no %s, no Dockerfile and no composer.json at HEAD, so there is nothing to build a job image from.', Devcontainer::PATH));
    }

    public static function withoutPhpRecord(): self
    {
        return new self('composer.json names no PHP version (config.platform.php or require.php), so no job image is built rather than one with another PHP.');
    }

    public static function notJson(string $file, string $reason): self
    {
        return new self(sprintf('%s is not valid JSON: %s.', $file, $reason));
    }

    public static function notAnObject(string $file, string $key): self
    {
        return new self(sprintf('%s has a %s that is not an object.', $file, $key));
    }

    public static function notAVersion(string $platform): self
    {
        return new self(sprintf('config.platform.php "%s" is not a version.', $platform));
    }

    public static function notAConstraint(string $constraint): self
    {
        return new self(sprintf('require.php "%s" is not a version constraint.', $constraint));
    }

    public static function platformOutsideRequire(string $platform, string $constraint): self
    {
        return new self(sprintf('config.platform.php %s does not meet require.php %s.', $platform, $constraint));
    }

    public static function notAnExtension(string $name): self
    {
        return new self(sprintf('"%s" in composer.json is not a PHP extension name.', $name));
    }

    /**
     * @param list<string> $minors
     */
    public static function noSupportedMinor(string $rule, array $minors): self
    {
        return new self(sprintf('No supported PHP minor (%s) meets require.php %s.', implode(', ', $minors), $rule));
    }

    public static function unusableCliManifest(string $manifest, string $reason): self
    {
        return new self(sprintf('The CLI manifest %s cannot be used: %s.', $manifest, $reason));
    }

    public static function composeUnsupported(): self
    {
        return new self(sprintf('%s uses dockerComposeFile, which Intakt does not build a job image from yet.', Devcontainer::PATH));
    }

    public static function withoutImageOrBuild(): self
    {
        return new self(sprintf('%s names neither an image nor a build with a dockerfile.', Devcontainer::PATH));
    }

    public static function notText(string $key): self
    {
        return new self(sprintf('%s has a %s that is not text.', Devcontainer::PATH, $key));
    }

    public static function contextOutside(string $context): self
    {
        return new self(sprintf('The build context "%s" of %s is outside the repository.', $context, Devcontainer::PATH));
    }

    public static function dockerfileOutsideContext(string $dockerfile): self
    {
        return new self(sprintf('The dockerfile "%s" of %s is outside its build context.', $dockerfile, Devcontainer::PATH));
    }

    public static function notAnImage(string $image): self
    {
        return new self(sprintf('The image "%s" of %s is not an image reference.', $image, Devcontainer::PATH));
    }

    public static function dockerfileMissing(string $path): self
    {
        return new self(sprintf('The Dockerfile %s is not a file at HEAD, so no review job image is built from it.', $path));
    }

    public static function notExtendable(string $reason): self
    {
        return new self(sprintf('The project\'s Dockerfile cannot get the review job\'s tools: %s.', $reason));
    }

    public static function notATag(string $tag): self
    {
        return new self(sprintf('"%s" is not an image tag: a lowercase name with an optional :tag.', $tag));
    }

    public static function unreadable(string $directory, string $reason): self
    {
        return new self(sprintf('%s is not a git checkout with a commit that git can read: %s', $directory, $reason));
    }

    // With the build's last lines, because the failed step says there what broke (a missing extension names itself).
    public static function buildFailed(string $tag, string $error, string $output): self
    {
        $lines = array_slice(explode("\n", rtrim($output)), -self::OUTPUT_LINES);

        return new self(sprintf("The image %s was not built: %s\n%s", $tag, $error, implode("\n", $lines)));
    }

    public static function probeFailed(string $tag, JobEnd $end): self
    {
        return new self(sprintf('The PHP probe in the image %s ended %s with exit code %d.', $tag, $end->cause->value, $end->exitCode));
    }

    public static function probeUnreadable(string $tag): self
    {
        return new self(sprintf('The PHP probe in the image %s wrote no version and extension list.', $tag));
    }

    public static function anotherPhp(string $tag, string $version, string $rule): self
    {
        return new self(sprintf('The image %s has PHP %s, which does not meet %s.', $tag, $version, $rule));
    }

    /**
     * @param list<string> $extensions
     */
    public static function missingExtensions(string $tag, array $extensions): self
    {
        return new self(sprintf('The image %s lacks the PHP extensions %s.', $tag, implode(', ', $extensions)));
    }
}
