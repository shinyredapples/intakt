<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Infrastructure;

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

    // Its shell, browsers, apps, plugins and agents would read past the checkout's path checks and the fence, so each tool the CLI enables by default is off; the CLI refuses a feature name it does not know, so a renamed one fails the call instead of staying on. Public because the review job's script switches off the same tools.
    public const array DISABLED_FEATURES = ['shell_tool', 'unified_exec', 'apps', 'plugins', 'browser_use', 'in_app_browser', 'computer_use', 'multi_agent', 'image_generation', 'view_image', 'hooks'];

    // The call home is writable, so no session is saved in it and no user config or rules are read from it; it is no repository, so the Git check is skipped.
    private const array CONFINEMENT = [
        'sandbox' => 'read-only',
        'ephemeral' => true,
        'ignore_user_config' => true,
        'ignore_rules' => true,
        'skip_git_repo_check' => true,
        'disable' => self::DISABLED_FEATURES,
    ];

    private const string NO_WEB_SEARCH = 'web_search="disabled"';

    private const array PROMPTS = ['prompt' => true, 'system_prompt' => true];

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

        return new ModelClient(null, $home, self::TIMEOUT, $this->environment($home))->request($model, $this->promptOf($payload), $this->pinned());
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
    private function pinned(): array
    {
        $settings = $this->settings->options();

        return [...self::CONFINEMENT, ...$settings, 'config' => [self::NO_WEB_SEARCH, ...$settings['config']]];
    }

    /**
     * @return array<string, string>
     */
    private function environment(string $home): array
    {
        // Process hands a child every inherited variable unless each is overridden by name, so Intakt's keys and secrets are emptied; the bridge accepts strings only.
        $inherited = array_fill_keys(array_map(strval(...), array_keys([...getenv(), ...$_ENV])), '');

        return [...$inherited, 'PATH' => (string) getenv('PATH'), 'HOME' => $home, 'CODEX_HOME' => $home];
    }
}
