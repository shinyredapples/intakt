<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Infrastructure;

use Override;
use Symfony\AI\Platform\Exception\IncompleteStreamException;
use Symfony\AI\Platform\Result\RawResultInterface;

// The bridge returns an empty answer when the CLI's stream ends without its final result event, and then fails with a generic error; naming it keeps a cut-off stream apart from a failed one.
final readonly class FinishedProcessResult implements RawResultInterface
{
    public function __construct(
        private RawResultInterface $inner,
    ) {
    }

    #[Override]
    public function getData(): array
    {
        $data = $this->inner->getData();

        if ([] === $data) {
            throw new IncompleteStreamException('The Claude Code stream ended before its final result.');
        }

        return $data;
    }

    #[Override]
    public function getDataStream(): iterable
    {
        return $this->inner->getDataStream();
    }

    #[Override]
    public function getObject(): object
    {
        return $this->inner->getObject();
    }
}
