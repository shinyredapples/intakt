<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Infrastructure;

use Intakt\Core\Ai\Contract\ReviewServerAccess;
use Override;
use Symfony\AI\Platform\Exception\InvalidArgumentException;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\ModelClientInterface;
use Symfony\AI\Platform\Result\RawResultInterface;
use Symfony\AI\Platform\StructuredOutput\PlatformSubscriber;

// The CLI is a full agent whose own file tools bypass the checkout's path checks and the fence, so it gets no built-in tool and no project settings, and no MCP server but the round's review server when it is given one; every other option the agent passes is dropped because the bridge turns each into a CLI flag.
final readonly class ConfinedClaudeCodeClient implements ModelClientInterface
{
    private const array CONFINEMENT = [
        'tools' => [],
        'setting_sources' => '',
        'strict_mcp_config' => true,
        'no_session_persistence' => true,
    ];

    // The built-in tools by name, denied even though the empty tool list already disables them, so a CLI release that reads one flag differently still finds them refused.
    private const array BUILT_IN_TOOLS = ['Bash', 'Read', 'Edit', 'MultiEdit', 'Write', 'NotebookEdit', 'Glob', 'Grep', 'LS', 'WebFetch', 'WebSearch', 'Task', 'TodoWrite'];

    private const string WITH_TOOLS = 'In this session only the read-only tools of the server %s are available: read the change through them, and cite only what the fenced text or a tool answer shows.';

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

        // Through the CLI's own option rather than as an instruction, because only the option holds the model to an answer that matches the schema.
        $schema = null === $format ? [] : ['json_schema' => $this->schemaOf($format)];

        $access = $options[ReviewServerAccess::OPTION] ?? null;

        if (!$access instanceof ReviewServerAccess) {
            return [...self::CONFINEMENT, 'append_system_prompt' => self::NO_TOOLS, ...$schema];
        }

        // Passed on whole, because the CLI's folder, where its configuration file is written, belongs to the client inside.
        return [
            ...self::CONFINEMENT,
            ReviewServerAccess::OPTION => $access,
            'allowed_tools' => [...$access->mcpToolNames()],
            'disallowed_tools' => self::BUILT_IN_TOOLS,
            'append_system_prompt' => sprintf(self::WITH_TOOLS, $access->name),
            ...$schema,
        ];
    }

    // Refused, because a CLI started without the schema would answer free text that no longer matches what the caller asked for.
    private function schemaOf(mixed $format): string
    {
        $schema = is_array($format) && is_array($format['json_schema'] ?? null) ? $format['json_schema']['schema'] ?? null : null;

        return is_array($schema) ? json_encode($schema, JSON_THROW_ON_ERROR) : throw new InvalidArgumentException('The response format holds no JSON schema for the Claude Code CLI.');
    }
}
