<?php

declare(strict_types=1);

namespace Intakt\Core\Job\UseCase\BuildJobImage;

use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// the console builds this object per run for #[MapInput], so the container has no reason to hold it
#[Exclude]
final class BuildJobImageInput
{
    #[Argument('The project checkout; the files of its HEAD commit decide the image')]
    public string $directory;

    #[Argument('The tag the image gets, for example intakt-job/demo:main')]
    public string $tag;

    #[Option('The image of the project as its source gives it, or of a review job, which adds the job tools to a project Dockerfile or devcontainer image')]
    public JobImageKind $kind = JobImageKind::Project;
}
