<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\SymfonyAiVendor;

use Override;
use Symfony\AI\Platform\Bridge\ClaudeCode\ClaudeCode;
use Symfony\AI\Platform\Bridge\ClaudeCode\RawProcessResult;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\ModelClientInterface;
use Symfony\AI\Platform\Result\RawResultInterface;
use Symfony\Component\Process\Process;

// Replays a recorded CLI stream through the bridge's own process result, so the bridge parses it as it would a live run.
final class RecordedClaudeCodeCli implements ModelClientInterface
{
    private const float STALL_TIMEOUT = 0.5;

    /**
     * @var array<string, mixed>
     */
    public array $options = [];

    private function __construct(
        private readonly string $recording,
        private readonly string $ending,
        private readonly ?float $timeout,
    ) {
    }

    public static function replaying(string $recording): self
    {
        return new self($recording, 'exit 0', null);
    }

    public static function exitingAfter(string $recording, int $exitCode): self
    {
        return new self($recording, 'exit '.$exitCode, null);
    }

    public static function killedAfter(string $recording): self
    {
        return new self($recording, 'kill -TERM $$', null);
    }

    public static function stalledAfter(string $recording): self
    {
        return new self($recording, 'sleep 10', self::STALL_TIMEOUT);
    }

    #[Override]
    public function supports(Model $model): bool
    {
        return $model instanceof ClaudeCode;
    }

    #[Override]
    public function request(Model $model, array|string $payload, array $options = []): RawResultInterface
    {
        $this->options = $options;
        $replay = new Process(['sh', '-c', 'cat "$0"; '.$this->ending, __DIR__.'/Fixtures/claude-code/'.$this->recording.'.jsonl'], timeout: $this->timeout);
        $replay->start();

        return new RawProcessResult($replay);
    }
}
