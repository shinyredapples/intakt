<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Git\UseCase\MakeBuildClone;

use Intakt\Core\Git\UseCase\MakeBuildClone\MakeBuildClone;
use Override;
use Symfony\Component\Filesystem\Filesystem;

// Forces the losing order of two calls for one folder, because the other clone must take it between this call's checks and its own folder.
final class FilesystemThatLetsAnotherCloneInFirst extends Filesystem
{
    public function __construct(
        private readonly MakeBuildClone $other,
        private readonly string $branch,
        private readonly string $clone,
    ) {
    }

    /**
     * @param string|iterable<mixed> $dirs
     */
    #[Override]
    public function mkdir(string|iterable $dirs, int $mode = 0o777): void
    {
        $this->other->make('alpha', $this->branch, $this->clone);
        parent::mkdir($dirs, $mode);
    }
}
