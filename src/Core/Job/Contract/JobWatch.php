<?php

declare(strict_types=1);

namespace Intakt\Core\Job\Contract;

// Asked while a job runs, so its caller can end the job's session early; the output it sees is what the job wrote so far, which the job can write anything into.
interface JobWatch
{
    public function intervalSeconds(): int;

    public function endsSession(JobOutput $live): bool;
}
