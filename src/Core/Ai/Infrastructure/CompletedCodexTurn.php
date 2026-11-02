<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Infrastructure;

use Override;
use Symfony\AI\Platform\Exception\IncompleteStreamException;
use Symfony\AI\Platform\Result\RawResultInterface;
use Symfony\Component\Process\Process;

// The bridge answers with the last message even when the stream ended before turn.completed, which carries the usage; naming it keeps a cut-off stream from passing for a complete answer, as with Claude Code (FinishedProcessResult; upstream/ai-codex-platform-cut-off-stream).
final readonly class CompletedCodexTurn implements RawResultInterface
{
    private const string ITEM = 'item';

    private const string USAGE = 'usage';

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

        // The CLI has already run the tools it used, so the bridge's copy of them is not the agent's to run again (upstream/ai-codex-platform-cli-tool-calls).
        unset($data[self::TOOL_CALLS]);

        return isset($data[self::ITEM]) ? $this->completed($data) : $this->withReportedUsage($data);
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

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     *
     * @throws IncompleteStreamException
     */
    private function completed(array $data): array
    {
        if (!isset($data[self::USAGE])) {
            throw new IncompleteStreamException('The Codex stream ended before its turn completed.');
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    // Read from the output because the bridge drops the usage of a turn completed without an agent message, though the account spent it (upstream/ai-codex-platform-usage-without-answer); the missing answer still fails the conversion.
    private function withReportedUsage(array $data): array
    {
        $usage = $this->reportedUsage();

        return null === $usage ? $data : [...$data, self::USAGE => $usage];
    }

    /**
     * @return array<mixed>|null
     */
    private function reportedUsage(): ?array
    {
        $process = $this->inner->getObject();
        if (!$process instanceof Process) {
            return null;
        }

        $usage = null;
        foreach (explode(PHP_EOL, $process->getOutput()) as $line) {
            $event = json_decode($line, true);
            if (is_array($event) && 'turn.completed' === ($event['type'] ?? null) && is_array($event[self::USAGE] ?? null)) {
                $usage = $event[self::USAGE];
            }
        }

        return $usage;
    }
}
