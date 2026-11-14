<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Infrastructure;

use Override;
use Symfony\AI\Platform\Exception\IncompleteStreamException;
use Symfony\AI\Platform\Exception\RuntimeException;
use Symfony\AI\Platform\Result\RawResultInterface;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

// The bridge answers a stream cut off before its final result with an empty answer, or with a generic error when the CLI was killed or timed out (upstream/ai-claude-code-platform-cut-off-stream); naming it keeps a cut-off stream apart from a failed one.
final readonly class FinishedProcessResult implements RawResultInterface
{
    private const string TOOL_CALLS = 'tool_calls';

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

    // The bridge reads only the result's text, while a CLI given a schema puts the answer held to it in the structured output; encoded whole, so the caller's own parser still refuses one that is not a verdict.
    /**
     * @param array<string|int, mixed> $data
     *
     * @return array<string|int, mixed>
     */
    private function withStructuredOutput(array $data): array
    {
        if (!array_key_exists(self::STRUCTURED_OUTPUT, $data)) {
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
        $process = $this->inner->getObject();

        return $process instanceof Process && array_any(
            explode(PHP_EOL, $process->getOutput()),
            $this->isResultEvent(...),
        );
    }

    private function isResultEvent(string $line): bool
    {
        $event = json_decode($line, true);

        return is_array($event) && 'result' === ($event['type'] ?? null);
    }

    private function cutOff(string $how, ?Throwable $failure): IncompleteStreamException
    {
        return new IncompleteStreamException('The Claude Code stream ended before its final result: '.$how.'.', 0, $failure);
    }
}
