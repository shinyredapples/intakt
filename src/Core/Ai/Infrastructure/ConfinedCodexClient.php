<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Infrastructure;

use Override;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\ModelClientInterface;
use Symfony\AI\Platform\Result\RawResultInterface;
use Symfony\AI\Platform\StructuredOutput\PlatformSubscriber;

// Every option the agent passes is dropped because the bridge turns each into a CLI flag; the instructions travel with the system prompt, which the bridge puts in front of the prompt, since the CLI has an option for neither.
final readonly class ConfinedCodexClient implements ModelClientInterface
{
    private const string SYSTEM_PROMPT = 'system_prompt';

    // Because the reviewer's instructions offer Intakt's tools, which the CLI cannot call (plan T15 serves them over MCP).
    private const string NO_TOOLS = 'In this session no tool is available: judge the change from the fenced text you were given, and cite only what it shows.';

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
        return new CompletedCodexTurn($this->inner->request($model, $payload, [self::SYSTEM_PROMPT => $this->instructions($payload, $options)]));
    }

    /**
     * @param array<string|int, mixed>|string $payload
     * @param array<string, mixed>            $options
     */
    private function instructions(array|string $payload, array $options): string
    {
        $system = is_array($payload) && is_string($payload[self::SYSTEM_PROMPT] ?? null) ? $payload[self::SYSTEM_PROMPT].PHP_EOL.PHP_EOL : '';
        $format = $options[PlatformSubscriber::RESPONSE_FORMAT] ?? null;
        $schema = null === $format ? '' : ' Answer with one JSON object that matches this JSON schema and nothing else: '.json_encode($format, JSON_THROW_ON_ERROR);

        return $system.self::NO_TOOLS.$schema;
    }
}
