<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Infrastructure;

use Override;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\ModelClientInterface;
use Symfony\AI\Platform\Result\RawResultInterface;
use Symfony\AI\Platform\StructuredOutput\PlatformSubscriber;

// The CLI is a full agent with shell and edit tools, so a reviewer gets only what the options below leave it: the agent's own tools and every other option are dropped because the bridge turns each option into a CLI flag.
final readonly class ReadOnlyClaudeCodeClient implements ModelClientInterface
{
    private const string TOOLS = 'Read,Grep,Glob';

    public function __construct(
        private ModelClientInterface $inner,
    ) {
    }

    #[Override]
    public function supports(Model $model): bool
    {
        return $this->inner->supports($model);
    }

    #[Override]
    public function request(Model $model, array|string $payload, array $options = []): RawResultInterface
    {
        return new FinishedProcessResult($this->inner->request($model, $payload, $this->confined($options)));
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function confined(array $options): array
    {
        $confined = ['tools' => self::TOOLS];
        $format = $options[PlatformSubscriber::RESPONSE_FORMAT] ?? null;

        // The CLI has no response format option, so the schema travels as an instruction.
        if (null !== $format) {
            $confined['append_system_prompt'] = 'Answer with one JSON object that matches this JSON schema and nothing else: '.json_encode($format, JSON_THROW_ON_ERROR);
        }

        return $confined;
    }
}
