<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Build;

use Intakt\Core\Job\Contract\JobCause;
use Intakt\Core\Job\Contract\JobEnd;
use Intakt\Core\Job\Contract\JobResult;
use Intakt\Core\Job\Contract\JobRunner;
use Intakt\Core\Job\Contract\JobSpec;
use Intakt\Tests\Core\Job\OutputFiles;
use LogicException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Finder\Finder;

// Serves the files of a folder as a build job's output, so the tests and the evidence of intakt:build:task need no container engine; ContainerJobRunner runs the jobs of an installed instance.
final readonly class ScriptedJobRunner implements JobRunner
{
    public function __construct(
        #[Autowire('%env(BUILD_JOB_OUTPUT)%')]
        private string $output,
    ) {
    }

    public function run(JobSpec $spec): JobResult
    {
        $folder = is_dir($this->output) ? $this->output : throw new LogicException('BUILD_JOB_OUTPUT names no folder.');
        $files = [];

        foreach (new Finder()->files()->in($folder)->depth(0) as $file) {
            $files[$file->getFilename()] = $file->getContents();
        }

        return new JobResult(new JobEnd(JobCause::Exited, 0), OutputFiles::of($files));
    }
}
