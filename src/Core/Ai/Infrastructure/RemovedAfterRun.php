<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Infrastructure;

use Override;
use Symfony\AI\Platform\Result\RawResultInterface;
use Symfony\Component\Filesystem\Filesystem;

// The CLI's MCP configuration holds the round's bearer, so the file stays on disk only until its run has been read.
final readonly class RemovedAfterRun implements RawResultInterface
{
    public function __construct(
        private RawResultInterface $inner,
        private string $file,
    ) {
    }

    #[Override]
    public function getData(): array
    {
        try {
            return $this->inner->getData();
        } finally {
            $this->remove();
        }
    }

    #[Override]
    public function getDataStream(): iterable
    {
        try {
            yield from $this->inner->getDataStream();
        } finally {
            $this->remove();
        }
    }

    #[Override]
    public function getObject(): object
    {
        return $this->inner->getObject();
    }

    private function remove(): void
    {
        new Filesystem()->remove($this->file);
    }
}
