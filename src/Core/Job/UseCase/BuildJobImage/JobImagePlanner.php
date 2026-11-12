<?php

declare(strict_types=1);

namespace Intakt\Core\Job\UseCase\BuildJobImage;

final readonly class JobImagePlanner
{
    private const string COMPOSER_JSON = 'composer.json';
    private const string DOCKERFILE = 'Dockerfile';

    public function __construct(private ProjectFiles $files)
    {
    }

    /**
     * @throws JobImageNotBuilt
     */
    public function planFor(string $directory): JobImagePlan
    {
        $php = $this->phpOf($directory);
        $plan = $this->fromDevcontainer($directory) ?? $this->fromDockerfile($directory) ?? $this->generatedFor($php);

        return $plan->withPhp($php);
    }

    // Read before any image source, so a composer.json without its PHP stops every source rather than letting an image bring some other PHP.
    private function phpOf(string $directory): ?PhpProfile
    {
        $composer = $this->files->read($directory, self::COMPOSER_JSON);

        return null === $composer ? null : PhpProfile::fromComposerJson($composer);
    }

    private function fromDevcontainer(string $directory): ?JobImagePlan
    {
        $devcontainer = $this->files->read($directory, Devcontainer::PATH);

        return null === $devcontainer ? null : Devcontainer::planFrom($devcontainer);
    }

    private function fromDockerfile(string $directory): ?JobImagePlan
    {
        return $this->files->exists($directory, self::DOCKERFILE) ? JobImagePlan::fromTree(JobImageSource::Dockerfile, '', self::DOCKERFILE) : null;
    }

    private function generatedFor(?PhpProfile $php): JobImagePlan
    {
        return $php instanceof PhpProfile ? JobImagePlan::generated(JobImageSource::Generated, GeneratedDockerfile::for($php)) : throw JobImageNotBuilt::withoutSource();
    }
}
