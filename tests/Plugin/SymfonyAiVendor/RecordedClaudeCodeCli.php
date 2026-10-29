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
    /**
     * @var array<string, mixed>
     */
    public array $options = [];

    public function __construct(
        private readonly string $recording,
    ) {
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
        $replay = new Process(['cat', __DIR__.'/Fixtures/claude-code/'.$this->recording.'.jsonl']);
        $replay->start();

        return new RawProcessResult($replay);
    }
}
