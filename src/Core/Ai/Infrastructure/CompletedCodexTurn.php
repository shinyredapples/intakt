<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Infrastructure;

use Override;
use Symfony\AI\Platform\Exception\IncompleteStreamException;
use Symfony\AI\Platform\Result\RawResultInterface;

// The bridge answers with the last message even when the stream ended before turn.completed, which carries the usage; naming it keeps a cut-off stream from passing for a complete answer, as with Claude Code (FinishedProcessResult).
final readonly class CompletedCodexTurn implements RawResultInterface
{
    private const string TOOL_CALLS = 'tool_calls';

    public function __construct(
        private RawResultInterface $inner,
    ) {
    }

    /**
     * @throws IncompleteStreamException
     */
    #[Override]
    public function getData(): array
    {
        $data = $this->inner->getData();

        if (isset($data['item']) && !isset($data['usage'])) {
            throw new IncompleteStreamException('The Codex stream ended before its turn completed.');
        }

        // The CLI has already run the tools it used, so the bridge's copy of them is not the agent's to run again.
        unset($data[self::TOOL_CALLS]);

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
