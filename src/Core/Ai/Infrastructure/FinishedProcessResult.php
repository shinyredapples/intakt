<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Infrastructure;

use Intakt\Core\Ai\Contract\ReviewServerAccess;
use Intakt\Core\Ai\Contract\ReviewServerReads;
use Override;
use Symfony\AI\Platform\Exception\IncompleteStreamException;
use Symfony\AI\Platform\Exception\RuntimeException;
use Symfony\AI\Platform\Result\RawResultInterface;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

// The bridge answers a stream cut off before its final result with an empty answer, or with a generic error when the CLI was killed or timed out (upstream/ai-claude-code-platform-cut-off-stream); naming it keeps a cut-off stream apart from a failed one.
final readonly class FinishedProcessResult implements RawResultInterface, ReviewServerReads
{
    private const string TOOL_CALLS = 'tool_calls';

    private const string TYPE = 'type';

    private const string STRUCTURED_OUTPUT = 'structured_output';

    private const string RESULT = 'result';

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
        try {
            $data = $this->inner->getData();
        } catch (ProcessTimedOutException $timeout) {
            throw $this->cutOffUnlessFinished($timeout, 'the CLI timed out');
        } catch (ProcessSignaledException $killed) {
            throw $this->cutOffUnlessFinished($killed, sprintf('the CLI was stopped by signal %d', $killed->getSignal()));
        } catch (RuntimeException $failed) {
            throw $this->cutOffUnlessFinished($failed, $failed->getMessage());
        }

        if ([] === $data) {
            throw $this->cutOff('the stream ended', null);
        }

        // The CLI has already run the tools it used, so the bridge's copy of them is not the agent's to run again (upstream/ai-claude-code-platform-cli-tool-calls).
        unset($data[self::TOOL_CALLS]);

        return $this->withStructuredOutput($data);
    }

    // Only a call of one of its tools on that server whose result the CLI reports without an error counts, because a failed or refused call, one left without a result or one to another server showed the reviewer nothing of the change; and only when the CLI reports that server connected, so a call it could not have reached it with never counts.
    #[Override]
    public function readThrough(ReviewServerAccess $access): bool
    {
        return $this->connected($access) && $this->readThroughTools($access);
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

    // The CLI's init event lists each MCP server it started with its status, so only `connected` shows the round's server was reached.
    private function connected(ReviewServerAccess $access): bool
    {
        $init = array_filter($this->events(), static fn (array $event): bool => 'system' === ($event[self::TYPE] ?? null) && 'init' === ($event['subtype'] ?? null));
        $servers = array_merge(...array_map(static fn (array $event): array => is_array($event['mcp_servers'] ?? null) ? array_values($event['mcp_servers']) : [], array_values($init)));

        return array_any($servers, static fn (mixed $server): bool => is_array($server) && $access->name === ($server['name'] ?? null) && 'connected' === ($server['status'] ?? null));
    }

    private function readThroughTools(ReviewServerAccess $access): bool
    {
        $toolNames = [...$access->mcpToolNames()];
        $calls = array_filter($this->contentBlocks('assistant', 'tool_use'), static fn (array $use): bool => in_array($use['name'] ?? null, $toolNames, true));
        $ids = array_column($calls, 'id');

        return array_any(
            $this->contentBlocks('user', 'tool_result'),
            static fn (array $result): bool => in_array($result['tool_use_id'] ?? null, $ids, true) && false === ($result['is_error'] ?? false),
        );
    }

    // The bridge reads only the result's text (upstream/ai-claude-code-platform-structured-output), while a CLI given a schema puts the answer held to it in the structured output; encoded whole, so the caller's own parser still refuses one that is not a verdict. A null field holds no answer, so the result's text stays the one read.
    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function withStructuredOutput(array $data): array
    {
        if (null === ($data[self::STRUCTURED_OUTPUT] ?? null)) {
            return $data;
        }

        return [...$data, self::RESULT => json_encode($data[self::STRUCTURED_OUTPUT], JSON_THROW_ON_ERROR)];
    }

    // A CLI that wrote its final result and then failed reported why, so that stays a failure.
    private function cutOffUnlessFinished(Throwable $failure, string $how): Throwable
    {
        return $this->endedWithResult() ? $failure : $this->cutOff($how, $failure);
    }

    private function endedWithResult(): bool
    {
        return array_any($this->events(), static fn (array $event): bool => 'result' === ($event[self::TYPE] ?? null));
    }

    /**
     * @return list<array<mixed>>
     */
    private function contentBlocks(string $eventType, string $blockType): array
    {
        $content = array_merge(...array_map(fn (array $event): array => $this->contentOf($event, $eventType), $this->events()));

        return array_values(array_filter($content, static fn (mixed $block): bool => is_array($block) && $blockType === ($block[self::TYPE] ?? null)));
    }

    /**
     * @param array<mixed> $event
     *
     * @return list<mixed>
     */
    private function contentOf(array $event, string $eventType): array
    {
        $message = $eventType === ($event[self::TYPE] ?? null) ? $event['message'] ?? null : null;
        $content = is_array($message) ? $message['content'] ?? null : null;

        return is_array($content) ? array_values($content) : [];
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

    private function cutOff(string $how, ?Throwable $failure): IncompleteStreamException
    {
        return new IncompleteStreamException('The Claude Code stream ended before its final result: '.$how.'.', 0, $failure);
    }
}
