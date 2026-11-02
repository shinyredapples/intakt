<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Infrastructure;

use Override;
use Symfony\AI\Platform\Bridge\Codex\Codex;
use Symfony\AI\Platform\Bridge\Codex\ModelClient;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\ModelClientInterface;
use Symfony\AI\Platform\Result\RawResultInterface;

// The CLI runs in the account's call home with a read-only copy of its login, so it never writes the kept one, and with the pinned model and effort, so no caller changes them.
final readonly class IsolatedCodexCli implements ModelClientInterface
{
    private const float TIMEOUT = 300.0;

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

        return new ModelClient(null, $home, self::TIMEOUT, $this->environment($home))->request($model, $payload, [...$options, ...$this->settings->options()]);
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
