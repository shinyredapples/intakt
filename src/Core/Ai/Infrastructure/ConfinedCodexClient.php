<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Infrastructure;

use Intakt\Core\Ai\Contract\ReviewServerAccess;
use Override;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\ModelClientInterface;
use Symfony\AI\Platform\Result\RawResultInterface;
use Symfony\AI\Platform\StructuredOutput\PlatformSubscriber;

// Every option the agent passes is dropped because the bridge turns each into a CLI flag, except the round's review server, which the CLI's client turns into its one MCP server; the instructions travel with the system prompt, which the bridge puts in front of the prompt, since the CLI has an option for neither.
final readonly class ConfinedCodexClient implements ModelClientInterface
{
    private const string PROMPT = 'prompt';

    private const string SYSTEM_PROMPT = 'system_prompt';

    private const string WITH_TOOLS = 'In this session only the read-only tools of the server %s are available: read the files you need through them before you give a verdict, and cite only what the fenced text or a tool answer shows.';

    // Because the reviewer's instructions offer Intakt's tools, which the CLI reaches only over the review server.
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
        $prompt = is_array($payload) ? $payload[self::PROMPT] ?? '' : $payload;
        $access = $options[ReviewServerAccess::OPTION] ?? null;
        $server = $access instanceof ReviewServerAccess ? [ReviewServerAccess::OPTION => $access] : [];

        return new CompletedCodexTurn($this->inner->request($model, [self::PROMPT => $prompt, self::SYSTEM_PROMPT => $this->instructions($payload, $options)], $server));
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
        $access = $options[ReviewServerAccess::OPTION] ?? null;
        $tools = $access instanceof ReviewServerAccess ? sprintf(self::WITH_TOOLS, $access->name) : self::NO_TOOLS;

        return $system.$tools.$schema;
    }
}
