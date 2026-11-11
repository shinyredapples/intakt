<?php

declare(strict_types=1);

namespace Intakt\Core\Job\UseCase\BuildJobImage;

use Intakt\Core\Job\Contract\DockerEngineFailed;
use Intakt\Core\Job\Contract\JobEventNotRecorded;
use Intakt\Core\Job\Contract\JobOutputRefused;
use Intakt\Core\Job\Contract\JobRefused;
use Intakt\Core\Job\Infrastructure\DockerEngine;

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
    public function build(string $directory, string $tag): BuiltJobImage
    {
        if (!ImageReference::isTag($tag)) {
            throw JobImageNotBuilt::notATag($tag);
        }

        $plan = $this->planner->planFor($directory);
        $build = $this->engine->build($this->context->of($plan, $directory), $tag, $plan->dockerfile);

        if (null !== $build->error || null === $build->image) {
            throw JobImageNotBuilt::buildFailed($tag, $build->error ?? 'no image', $build->output);
        }

        return new BuiltJobImage($plan, $build->output, $build->image, $plan->php instanceof PhpProfile ? $this->php->verify($tag, $build->image, $plan->php) : null);
    }
}
