<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Infrastructure;

use Override;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\ModelClientInterface;
use Symfony\AI\Platform\Result\RawResultInterface;
use Symfony\AI\Platform\StructuredOutput\PlatformSubscriber;

// The CLI is a full agent whose own file tools bypass the checkout's path checks and the fence, so it gets no tool, no project settings and no MCP server; every option the agent passes is dropped because the bridge turns each into a CLI flag.
final readonly class ConfinedClaudeCodeClient implements ModelClientInterface
{
    private const array CONFINEMENT = [
        'tools' => [],
        'setting_sources' => '',
        'strict_mcp_config' => true,
        'no_session_persistence' => true,
    ];

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
        return new FinishedProcessResult($this->inner->request($model, $payload, $this->confined($options)));
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function confined(array $options): array
    {
        $format = $options[PlatformSubscriber::RESPONSE_FORMAT] ?? null;

        // The CLI has no response format option, so the schema travels as an instruction.
        $schema = null === $format ? '' : ' Answer with one JSON object that matches this JSON schema and nothing else: '.json_encode($format, JSON_THROW_ON_ERROR);

        return [...self::CONFINEMENT, 'append_system_prompt' => self::NO_TOOLS.$schema];
    }
}
