<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Infrastructure;

use Intakt\Core\Ai\Contract\ReviewServerAccess;
use Override;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\ModelClientInterface;
use Symfony\AI\Platform\Result\RawResultInterface;
use Symfony\AI\Platform\StructuredOutput\PlatformSubscriber;

// The CLI is a full agent whose own file tools bypass the checkout's path checks and the fence, so it gets no built-in tool and no project settings, and at most the read-only tools of the round's review server; every option the agent passes is dropped because the bridge turns each into a CLI flag.
final readonly class ConfinedClaudeCodeClient implements ModelClientInterface
{
    // Fixed because the CLI prefixes the server's tools with it as mcp__<name>__<tool>, and the allow-list must spell them the same.
    private const string SERVER = 'intakt-review';

    // Named although `tools => []` already turns every built-in off, so a CLI that stops honoring that still has each one denied.
    private const array BUILT_IN_TOOLS = ['Bash', 'BashOutput', 'KillShell', 'Read', 'Edit', 'MultiEdit', 'Write', 'NotebookEdit', 'Glob', 'Grep', 'WebFetch', 'WebSearch', 'Task', 'TodoWrite', 'SlashCommand', 'ExitPlanMode'];

    private const array CONFINEMENT = [
        'tools' => [],
        'setting_sources' => '',
        'strict_mcp_config' => true,
        'no_session_persistence' => true,
    ];

    // Because the reviewer's instructions offer Intakt's tools, which this call cannot use without the review server.
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
        $server = $options[ReviewServerAccess::OPTION] ?? null;

        // The CLI has no response format option, so the schema travels as an instruction.
        $schema = null === $format ? '' : ' Answer with one JSON object that matches this JSON schema and nothing else: '.json_encode($format, JSON_THROW_ON_ERROR);

        if (!$server instanceof ReviewServerAccess) {
            return [...self::CONFINEMENT, 'append_system_prompt' => self::NO_TOOLS.$schema];
        }

        return [...self::CONFINEMENT, ...$this->reading($server), 'append_system_prompt' => $this->readingInstruction($server).$schema];
    }

    /**
     * @return array<string, string>
     */
    private function reading(ReviewServerAccess $server): array
    {
        $connection = ['mcpServers' => [self::SERVER => ['type' => 'http', 'url' => $server->url, 'headers' => ['Authorization' => 'Bearer '.$server->credential]]]];

        // One comma separated value each, because a repeated flag of a variadic option is not guaranteed to accumulate.
        return [
            'mcp_config' => json_encode($connection, JSON_THROW_ON_ERROR),
            'allowed_tools' => implode(',', $this->toolNames($server)),
            'disallowed_tools' => implode(',', self::BUILT_IN_TOOLS),
        ];
    }

    private function readingInstruction(ReviewServerAccess $server): string
    {
        return 'In this session only these read-only tools of the repository under review are available: '.implode(', ', $this->toolNames($server)).'. Read the change with them, and cite only what they or the fenced text show.';
    }

    /**
     * @return list<string>
     */
    private function toolNames(ReviewServerAccess $server): array
    {
        return array_map(static fn (string $tool): string => 'mcp__'.self::SERVER.'__'.$tool, $server->tools);
    }
}
