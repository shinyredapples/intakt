<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Infrastructure;

use Intakt\Core\Ai\Contract\ReviewServerAccess;
use Override;
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

        // The CLI has no response format option, so the schema travels as an instruction.
        $schema = null === $format ? '' : ' Answer with one JSON object that matches this JSON schema and nothing else: '.json_encode($format, JSON_THROW_ON_ERROR);

        $access = $options[ReviewServerAccess::OPTION] ?? null;

        if (!$access instanceof ReviewServerAccess) {
            return [...self::CONFINEMENT, 'append_system_prompt' => self::NO_TOOLS.$schema];
        }

        return [
            ...self::CONFINEMENT,
            'mcp_config' => json_encode(['mcpServers' => [$access->name => ['type' => 'http', 'url' => $access->url, 'headers' => ['Authorization' => 'Bearer '.$access->bearer]]]], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'allowed_tools' => array_map(static fn (string $tool): string => sprintf('mcp__%s__%s', $access->name, $tool), $access->tools),
            'disallowed_tools' => self::BUILT_IN_TOOLS,
            'append_system_prompt' => sprintf(self::WITH_TOOLS, $access->name).$schema,
        ];
    }
}
