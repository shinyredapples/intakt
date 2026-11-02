<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Infrastructure;

use Override;
use Symfony\AI\Platform\Exception\IncompleteStreamException;
use Symfony\AI\Platform\Exception\RuntimeException;
use Symfony\AI\Platform\Result\RawResultInterface;
use Symfony\Component\Process\Process;

// The bridge answers with the last message even when the stream ended before turn.completed, which carries the usage; naming it keeps a cut-off stream from passing for a complete answer, as with Claude Code (FinishedProcessResult; upstream/ai-codex-platform-cut-off-stream).
final readonly class CompletedCodexTurn implements RawResultInterface
{
    private const string ITEM = 'item';

    private const string USAGE = 'usage';

    private const string TOOL_CALLS = 'tool_calls';

    private const string TYPE = 'type';

    private const string MESSAGE = 'message';

    private const string ERROR = 'error';

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
            $this->refuseAStructuredError();

            return $this->failedWithReportedUsage($processFailed);
        }

        $this->refuseAStructuredError();

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
     * @throws RuntimeException
     */
    // Read from the turn's own failure only, so a reviewer whose text names a limit or a login is never an account outcome.
    private function refuseAStructuredError(): void
    {
        $failed = array_last(array_filter($this->events(), static fn (array $event): bool => 'turn.failed' === ($event[self::TYPE] ?? null)));
        $reported = $failed[self::ERROR] ?? null;
        $message = is_array($reported) ? $reported[self::MESSAGE] ?? null : null;
        $error = is_string($message) ? CodexStructuredError::of($message) : null;

        if ($error instanceof CodexStructuredError) {
            throw $error->failure();
        }
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
     * @return array<string, mixed>
     *
     * @throws RuntimeException
     */
    // The bridge throws on a non-zero exit before it reads the output, so the usage of a turn the CLI completed and then failed on would be lost though the account spent it; an error message keeps the conversion failing the reviewer while the extractor reads the usage.
    private function failedWithReportedUsage(RuntimeException $processFailed): array
    {
        $usage = $this->reportedUsage();
        if (null === $usage) {
            throw $processFailed;
        }

        return [self::TYPE => self::ERROR, self::MESSAGE => $processFailed->getMessage(), self::USAGE => $usage];
    }

    /**
     * @return array<mixed>|null
     */
    private function reportedUsage(): ?array
    {
        $completed = array_last(array_filter($this->events(), static fn (array $event): bool => 'turn.completed' === ($event[self::TYPE] ?? null) && is_array($event[self::USAGE] ?? null)));

        return $completed[self::USAGE] ?? null;
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

        return array_values(array_filter(array_map(static fn (string $line): mixed => json_decode($line, true), explode(PHP_EOL, $process->getOutput())), is_array(...)));
    }
}
