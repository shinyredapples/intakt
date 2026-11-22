<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Infrastructure;

use Intakt\Core\Ai\Contract\ReviewServerAccess;
use Override;
use Symfony\AI\Platform\Bridge\Codex\Codex;
use Symfony\AI\Platform\Bridge\Codex\ModelClient;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\ModelClientInterface;
use Symfony\AI\Platform\Result\RawResultInterface;

// The CLI runs in the account's call home with a read-only copy of its login, so it never writes the kept one, and with the pinned model and effort and the confinement, so no caller changes them.
final readonly class IsolatedCodexCli implements ModelClientInterface
{
    private const float TIMEOUT = 300.0;

    // Its shell, browsers, apps, plugins and agents would read past the checkout's path checks and the fence, so each tool the CLI enables by default is off; the CLI refuses a feature name it does not know, so a renamed one fails the call instead of staying on. No saved session, no user config or rules from the writable home, no Git check of a home that is no repository.
    private const array CONFINEMENT = [
        'sandbox' => 'read-only',
        'ephemeral' => true,
        'ignore_user_config' => true,
        'ignore_rules' => true,
        'skip_git_repo_check' => true,
        'disable' => ['shell_tool', 'unified_exec', 'apps', 'plugins', 'browser_use', 'in_app_browser', 'computer_use', 'multi_agent', 'image_generation', 'view_image', 'hooks'],
    ];

    private const string NO_WEB_SEARCH = 'web_search="disabled"';

    private const array PROMPTS = ['prompt' => true, 'system_prompt' => true];

    // The CLI reads the bearer from this variable, so it is never on the command line, which the bridge logs and Process puts into its timeout message.
    private const string BEARER = 'INTAKT_REVIEW_SERVER_BEARER';

    public function __construct(
        private CodexLogins $logins,
        private CodexSettings $settings,
        private string $account,
    ) {
    }

    #[Override]
    public function supports(Model $model): bool
    {
        return $model instanceof Codex;
    }

    #[Override]
    public function request(Model $model, array|string $payload, array $options = []): RawResultInterface
    {
        $home = $this->logins->handOver($this->account);
        $access = $options[ReviewServerAccess::OPTION] ?? null;
        $access = $access instanceof ReviewServerAccess ? $access : null;

        return new ModelClient(null, $home, self::TIMEOUT, $this->environment($home, $access))->request($model, $this->promptOf($payload), $this->pinned($access));
    }

    /**
     * @param array<string|int, mixed>|string $payload
     *
     * @return array<string, mixed>|string
     */
    // The bridge turns every other payload key and every option into a CLI flag, so only the prompts pass and the options are Intakt's alone.
    private function promptOf(array|string $payload): array|string
    {
        return is_string($payload) ? $payload : array_intersect_key($payload, self::PROMPTS);
    }

    /**
     * @return array<string, mixed>
     */
    private function pinned(?ReviewServerAccess $access): array
    {
        $settings = $this->settings->options();

        return [...self::CONFINEMENT, ...$settings, 'config' => [self::NO_WEB_SEARCH, ...$settings['config'], ...$this->serverOf($access)]];
    }

    /**
     * @return list<string>
     */
    // The round's server is the CLI's only MCP server, since the user config that could name others is ignored; required, so a server that cannot start fails the call instead of leaving a review that read nothing.
    private function serverOf(?ReviewServerAccess $access): array
    {
        if (!$access instanceof ReviewServerAccess) {
            return [];
        }

        $key = 'mcp_servers.'.$access->name.'.';

        return [
            $key.'url='.$this->toml($access->url),
            $key.'bearer_token_env_var='.$this->toml(self::BEARER),
            $key.'enabled_tools='.$this->toml([...$access->tools]),
            $key.'required=true',
        ];
    }

    /**
     * @param string|list<string> $value
     */
    // Encoded as JSON because the CLI parses each override as TOML, and a JSON string or list of strings is valid TOML, so a quote in a value cannot end it early.
    private function toml(string|array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return array<string, string>
     */
    private function environment(string $home, ?ReviewServerAccess $access): array
    {
        // Process hands a child every inherited variable unless each is overridden by name, so Intakt's keys and secrets are emptied; the bridge accepts strings only.
        $inherited = array_fill_keys(array_map(strval(...), array_keys([...getenv(), ...$_ENV])), '');
        $bearer = $access instanceof ReviewServerAccess ? [self::BEARER => $access->bearer] : [];

        return [...$inherited, 'PATH' => (string) getenv('PATH'), 'HOME' => $home, 'CODEX_HOME' => $home, ...$bearer];
    }
}
