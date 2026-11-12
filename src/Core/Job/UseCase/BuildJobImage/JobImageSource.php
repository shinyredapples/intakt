<?php

declare(strict_types=1);

namespace Intakt\Core\Job\UseCase\BuildJobImage;

enum JobImageSource: string
{
    case Devcontainer = 'devcontainer';
    case Dockerfile = 'dockerfile';
    case Generated = 'generated';

    public function label(): string
    {
        return match ($this) {
            self::Devcontainer => Devcontainer::PATH,
            self::Dockerfile => 'Dockerfile',
            self::Generated => 'composer.json (generated)',
        };
    }
}
