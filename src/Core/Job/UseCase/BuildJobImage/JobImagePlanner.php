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
    public function planFor(string $directory, JobImageKind $kind = JobImageKind::Project): JobImagePlan
    {
        $php = $this->phpOf($directory);
        $plan = $this->fromDevcontainer($directory) ?? $this->fromDockerfile($directory) ?? $this->generatedFor($php);

        return JobImageKind::Review === $kind ? $this->forReview($plan->withPhp($php), $directory) : $plan->withPhp($php);
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

    // A generated plan stays as it is, because the generated Dockerfile installs the job tools already.
    private function forReview(JobImagePlan $plan, string $directory): JobImagePlan
    {
        if (JobImageSource::Generated === $plan->source) {
            return $plan;
        }

        return $plan->withReviewTools($plan->generated ?? $this->projectDockerfileOf($plan, $directory));
    }

    private function projectDockerfileOf(JobImagePlan $plan, string $directory): string
    {
        $path = '' === $plan->tree ? $plan->dockerfile : $plan->tree.'/'.$plan->dockerfile;

        return $this->files->read($directory, $path) ?? throw JobImageNotBuilt::dockerfileMissing($path);
    }
}
