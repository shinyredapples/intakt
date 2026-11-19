<?php

declare(strict_types=1);

namespace Intakt\Core\Job\UseCase\BuildJobImage;

use Intakt\Core\Job\Contract\DockerEngineFailed;
use Intakt\Core\Job\Contract\JobEventNotRecorded;
use Intakt\Core\Job\Contract\JobOutputRefused;
use Intakt\Core\Job\Contract\JobRefused;
use Intakt\Core\Job\Infrastructure\DockerEngine;
use Intakt\Core\Job\Infrastructure\ImageBuild;

final readonly class BuildJobImage
{
    public function __construct(
        private JobImagePlanner $planner,
        private BuildContext $context,
        private DockerEngine $engine,
        private PhpInImage $php,
    ) {
    }

    /**
     * @throws JobImageNotBuilt
     * @throws JobRefused
     * @throws DockerEngineFailed
     * @throws JobOutputRefused
     * @throws JobEventNotRecorded
     */
    public function build(string $directory, string $tag, JobImageKind $kind = JobImageKind::Project): BuiltJobImage
    {
        if (!ImageReference::isTag($tag)) {
            throw JobImageNotBuilt::notATag($tag);
        }

        $plan = $this->planner->planFor($directory, $kind);
        $build = $this->sent($plan, $directory, $tag);

        if (null !== $build->error || null === $build->image) {
            throw JobImageNotBuilt::buildFailed($tag, $build->error ?? 'no image', $build->output);
        }

        return new BuiltJobImage($plan, $build->output, $build->image, $plan->php instanceof PhpProfile ? $this->php->verify($tag, $build->image, $plan->php) : null);
    }

    // Removed in finally, because the file must be gone after the request whether the engine built, refused or could not be reached.
    private function sent(JobImagePlan $plan, string $directory, string $tag): ImageBuild
    {
        $file = $this->context->of($plan, $directory);

        try {
            return $this->engine->build($file, $tag, $plan->dockerfile);
        } finally {
            $this->context->remove($file);
        }
    }
}
