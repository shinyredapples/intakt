<?php

declare(strict_types=1);

namespace Intakt\Core\Job\UseCase\BuildJobImage;

final readonly class GeneratedDockerfile
{
    // The minors with an official image that still get fixes, newest first, so a constraint gets the newest one it admits.
    private const array MINORS = ['8.5', '8.4', '8.3', '8.2'];
    // The release image's Composer pin, so the jobs of every project install with the Composer Intakt itself is built with.
    private const string COMPOSER = 'composer:2.11.1@sha256:8de2184c6de224f4689e8f80ed5f5edaf0030b6b8b7839a22a2bcb6722ac87d0';
    private const string EXTENSION_INSTALLER = 'mlocati/php-extension-installer:2.13.2';
    private const string CLAUDE_CODE = '@anthropic-ai/claude-code@2.1.322';
    private const string CODEX = '@openai/codex@0.167.1';

    /**
     * @throws JobImageNotBuilt
     */
    public static function for(PhpProfile $php): string
    {
        return implode("\n", [
            sprintf('FROM php:%s-cli', self::baseVersionFor($php)),
            sprintf('COPY --from=%s /usr/bin/composer /usr/local/bin/composer', self::COMPOSER),
            sprintf('COPY --from=%s /usr/bin/install-php-extensions /usr/local/bin/install-php-extensions', self::EXTENSION_INSTALLER),
            // curl because the job's start check tries each allowlisted host with it, git because builders and reviewers work in a checkout.
            'RUN apt-get update \\',
            '    && apt-get install -y --no-install-recommends ca-certificates curl git unzip nodejs npm \\',
            '    && rm -rf /var/lib/apt/lists/*',
            sprintf('RUN npm install --global %s %s \\', self::CLAUDE_CODE, self::CODEX),
            '    && npm cache clean --force',
            ...self::extensionStep($php->extensions),
            '',
        ]);
    }

    // Only the platform's own minor when there is one, because the platform version is the PHP Composer resolved the lock file for.
    private static function baseVersionFor(PhpProfile $php): string
    {
        $minors = null === $php->platform ? self::MINORS : [implode('.', array_slice(explode('.', $php->platform), 0, 2))];

        foreach ($minors as $minor) {
            $version = $php->imageVersionIn($minor);

            if (null !== $version) {
                return $version;
            }
        }

        throw JobImageNotBuilt::noSupportedMinor($php->rule, $minors);
    }

    /**
     * @param list<string> $extensions
     *
     * @return list<string>
     */
    private static function extensionStep(array $extensions): array
    {
        if ([] === $extensions) {
            return [];
        }

        // Compared in Composer's spelling, because php -m prints names such as "Zend OPcache"; still missing after the installer, the build must fail with its name.
        return [
            sprintf('RUN set -eu; for extension in %s; do \\', implode(' ', $extensions)),
            '        php -m | tr \'A-Z \' \'a-z-\' | grep -qx "$extension" || install-php-extensions "$extension" || true; \\',
            '        php -m | tr \'A-Z \' \'a-z-\' | grep -qx "$extension" || { echo "missing PHP extension: $extension" >&2; exit 1; }; \\',
            '    done',
        ];
    }
}
