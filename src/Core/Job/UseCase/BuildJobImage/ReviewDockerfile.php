<?php

declare(strict_types=1);

namespace Intakt\Core\Job\UseCase\BuildJobImage;

// A project's own Dockerfile need not hold the CLIs, git or curl a review job runs, so they are added after its last stage, which is the image built.
final readonly class ReviewDockerfile
{
    // A file of Intakt's own beside the project's files, so the project's Dockerfile is sent as it is committed.
    public const string PATH = '.intakt-review.Dockerfile';
    private const string DIRECTIVE = '/^#\s*([a-z]+)\s*=\s*(\S*)\s*$/i';

    /**
     * @throws JobImageNotBuilt
     */
    public static function after(string $dockerfile): string
    {
        self::refuseAnotherEscape($dockerfile);
        self::refuseAnOpenContinuation($dockerfile);

        // As root, because the project's Dockerfile may end as another user, who could not install packages.
        return implode("\n", [rtrim($dockerfile), '', '# Added by Intakt for a review job.', 'USER root', ...JobTools::steps(), '']);
    }

    // The added steps continue their lines with a backslash, which another escape character would turn into part of the command.
    private static function refuseAnotherEscape(string $dockerfile): void
    {
        foreach (explode("\n", $dockerfile) as $line) {
            if (1 !== preg_match(self::DIRECTIVE, trim($line), $directive)) {
                return;
            }

            if ('escape' === strtolower($directive[1]) && '\\' !== $directive[2]) {
                throw JobImageNotBuilt::notExtendable(sprintf('it sets the escape character to "%s"', $directive[2]));
            }
        }
    }

    // Docker skips blank and comment lines inside a continued line, so the added steps would join the last instruction.
    private static function refuseAnOpenContinuation(string $dockerfile): void
    {
        $instructions = array_filter(explode("\n", $dockerfile), static fn (string $line): bool => '' !== trim($line) && !str_starts_with(ltrim($line), '#'));

        if (str_ends_with(rtrim((string) array_last($instructions)), '\\')) {
            throw JobImageNotBuilt::notExtendable('its last line continues on a next line');
        }
    }
}
