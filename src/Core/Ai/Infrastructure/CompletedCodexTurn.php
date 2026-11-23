<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Infrastructure;

use Intakt\Core\Ai\Contract\ReviewServerAccess;
use Intakt\Core\Ai\Contract\ReviewServerReads;
use Override;
use Symfony\AI\Platform\Exception\IncompleteStreamException;
use Symfony\AI\Platform\Exception\RuntimeException;
use Symfony\AI\Platform\Result\RawResultInterface;
use Symfony\Component\Process\Process;

// The bridge answers with the last message even when the stream ended before turn.completed, which carries the usage; naming it keeps a cut-off stream from passing for a complete answer, as with Claude Code (FinishedProcessResult; upstream/ai-codex-platform-cut-off-stream).
final readonly class CompletedCodexTurn implements RawResultInterface, ReviewServerReads
{
    private const string ITEM = 'item';

    private const string USAGE = 'usage';

    private const string TOOL_CALLS = 'tool_calls';

    private const string TYPE = 'type';

    private const string MESSAGE = 'message';

    public function __construct(
        private RawResultInterface $inner,
    ) {
    }

    /**
     * @throws IncompleteStreamException
     * @throws RuntimeException
     */
    #[Override]
    public function getData(): array
    {
        try {
            $data = $this->inner->getData();
        } catch (RuntimeException $processFailed) {
            return $this->failedWithReportedUsage($processFailed);
        }

        // The CLI has already run the tools it used, so the bridge's copy of them is not the agent's to run again (upstream/ai-codex-platform-cli-tool-calls).
        unset($data[self::TOOL_CALLS]);

        return isset($data[self::ITEM]) ? $this->completed($data) : $this->withReportedUsage($data);
    }

    // Only a completed, unrefused call of one of its tools on that server counts, because a failed or refused call or one to another server showed the reviewer nothing of the change.
    #[Override]
    public function readThrough(ReviewServerAccess $access): bool
    {
        foreach ($this->events() as $event) {
            $item = 'item.completed' === ($event[self::TYPE] ?? null) ? $event[self::ITEM] ?? null : null;
            if (is_array($item) && $this->readOf($item, $access)) {
                return true;
            }
        }

        return false;
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
     * @return array<string, mixed>
     *
     * @throws RuntimeException
     */
    // The bridge throws on a non-zero exit before it reads the output, so the usage of a turn the CLI completed and then failed on would be lost though the account spent it; an error message keeps the conversion failing the reviewer while the extractor reads the usage.
    private function failedWithReportedUsage(RuntimeException $processFailed): array
    {
        $usage = $this->reportedUsage();
        if (null === $usage) {
            $this->failForAnAccountFailure();

            throw $processFailed;
        }

        return [self::TYPE => 'error', self::MESSAGE => $processFailed->getMessage(), self::USAGE => $usage];
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
        if (null === $usage) {
            $this->failForAnAccountFailure();
        }

        return null === $usage ? $data : [...$data, self::USAGE => $usage];
    }

    /**
     * @return array<mixed>|null
     */
    private function reportedUsage(): ?array
    {
        $usage = null;
        foreach ($this->events() as $event) {
            if ('turn.completed' === ($event[self::TYPE] ?? null) && is_array($event[self::USAGE] ?? null)) {
                $usage = $event[self::USAGE];
            }
        }

        return $usage;
    }

    /**
     * @throws RuntimeException
     */
    // Asked only of a turn that never completed, and read only from the CLI's own error events, never from an agent message, so a reviewer's text cannot take an account out.
    private function failForAnAccountFailure(): void
    {
        foreach ($this->events() as $event) {
            $failure = CodexAccountFailure::of($this->errorMessage($event));
            if ($failure instanceof CodexAccountFailure) {
                throw $failure->exception();
            }
        }
    }

    /**
     * @param array<mixed> $event
     */
    private function errorMessage(array $event): string
    {
        $message = match ($event[self::TYPE] ?? null) {
            'error' => $event[self::MESSAGE] ?? null,
            'turn.failed' => is_array($event['error'] ?? null) ? $event['error'][self::MESSAGE] ?? null : null,
            default => null,
        };

        return is_string($message) ? $message : '';
    }

    /**
     * @return list<array<mixed>>
     */
    private function events(): array
    {
        $process = $this->inner->getObject();
        if (!$process instanceof Process) {
            return [];
        }

        $events = array_map(static fn (string $line): mixed => json_decode($line, true), explode(PHP_EOL, $process->getOutput()));

        return array_values(array_filter($events, is_array(...)));
    }

    /**
     * @param array<mixed> $item
     */
    private function readOf(array $item, ReviewServerAccess $access): bool
    {
        return 'mcp_tool_call' === ($item[self::TYPE] ?? null)
            && $access->name === ($item['server'] ?? null)
            && in_array($item['tool'] ?? null, $access->tools, true)
            && 'completed' === ($item['status'] ?? null)
            && null === ($item['error'] ?? null)
            && $this->answered($item['result'] ?? null);
    }

    // A tool refusal (a judged round, a path outside the change) still completes the call, so only a result that is not marked as an error showed the reviewer anything.
    private function answered(mixed $result): bool
    {
        return is_array($result) && false === ($result['isError'] ?? false);
    }
}
