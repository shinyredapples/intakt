<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Job;

use Intakt\Core\Job\Contract\JobFollowedFile;
use Intakt\Core\Job\Contract\JobOutputPiece;

// A followed file whose whole text so far is given at each look, so a test scripts a log as the CLI grows it; null stands for a file that is gone.
final readonly class FollowedText implements JobFollowedFile
{
    public function __construct(
        private ?string $text,
        private string $identity = 'build.log',
    ) {
    }

    public function read(int $from, int $maxBytes): ?JobOutputPiece
    {
        return null === $this->text ? null : new JobOutputPiece($this->identity, strlen($this->text), substr($this->text, $from, $maxBytes));
    }
}
