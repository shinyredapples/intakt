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

        return new ModelClient(null, $home, self::TIMEOUT, $this->environment($home))->request($model, $payload, $this->pinned($options));
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function pinned(array $options): array
    {
        $settings = $this->settings->options();

        return [...$options, ...self::CONFINEMENT, ...$settings, 'config' => [self::NO_WEB_SEARCH, ...$settings['config']]];
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
