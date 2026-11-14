<?php

declare(strict_types=1);

namespace Intakt\Core\Job\UseCase\BuildJobImage;

final readonly class JobTools
{
    /**
     * @return list<string>
     *
     * @throws JobImageNotBuilt
     */
    public static function steps(): array
    {
        return [
            // curl because the job's start check tries each allowlisted host with it, git because builders and reviewers work in a checkout.
            'RUN apt-get update \\',
            '    && apt-get install -y --no-install-recommends ca-certificates curl git unzip nodejs npm \\',
            '    && rm -rf /var/lib/apt/lists/*',
            sprintf('RUN npm install --global %s \\', implode(' ', CliManifest::installArgumentsFrom(CliManifest::PATH))),
            '    && npm cache clean --force',
        ];
    }
}
