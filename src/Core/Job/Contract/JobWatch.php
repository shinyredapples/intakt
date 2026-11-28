<?php

declare(strict_types=1);

namespace Intakt\Core\Job\Contract;

// Asked while a job runs, so its caller can end the job's session early; the file it follows is one the job can write anything into.
interface JobWatch
{
    public function intervalSeconds(): int;

    // The output file the watch reads while the job runs; the end of the job hands back only its tail, because a file that is read in pieces may grow past the output's bound.
    public function followedFile(): string;

    public function endsSession(JobFollowedFile $file): bool;
}
