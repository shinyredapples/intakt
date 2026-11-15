<?php

declare(strict_types=1);

namespace Intakt\Core\Bench\UseCase\RunBench;

use Intakt\Core\Bench\Contract\BenchReview;
use Intakt\Core\Bench\UseCase\ReviewBenchChange\BenchChangeCheckout;
use Intakt\Core\Bench\UseCase\ReviewBenchChange\ReviewBenchChange;
use Intakt\Core\Platform\Contract\ReportableFailure;
use Symfony\Component\Filesystem\Filesystem;

final readonly class RunBench
{
    public function __construct(
        private BenchChangeCheckout $checkout,
        private ReviewBenchChange $review,
        private Filesystem $filesystem = new Filesystem(),
    ) {
    }

    /**
     * @return list<BenchReview>
     *
     * @throws ReportableFailure
     */
    public function reviewEveryChange(string $project): array
    {
        return array_map(fn (string $change): BenchReview => $this->reviewed($change, $project), $this->checkout->names());
    }

    // A checkout of its own per change, removed also when the review fails, because a change must start from the base commit.
    private function reviewed(string $change, string $project): BenchReview
    {
        $directory = sys_get_temp_dir().'/intakt-bench-'.bin2hex(random_bytes(4));

        try {
            return $this->review->of($this->checkout->open($change, $project, $directory));
        } finally {
            $this->filesystem->remove($directory);
        }
    }
}
