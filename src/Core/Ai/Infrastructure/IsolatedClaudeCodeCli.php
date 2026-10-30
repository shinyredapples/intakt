<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Infrastructure;

use Override;
use SensitiveParameter;
use Symfony\AI\Platform\Bridge\ClaudeCode\ClaudeCode;
use Symfony\AI\Platform\Bridge\ClaudeCode\ModelClient;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\ModelClientInterface;
use Symfony\AI\Platform\Result\RawResultInterface;
use Symfony\Component\Filesystem\Filesystem;

// The CLI runs in a directory of its own, so it never sees Intakt's install, its env files and secrets, or a checkout's settings and instructions.
final readonly class IsolatedClaudeCodeCli implements ModelClientInterface
{
    private const float TIMEOUT = 300.0;

    public function __construct(
        private string $directory,
        #[SensitiveParameter]
        private string $token,
    ) {
    }

    #[Override]
    public function supports(Model $model): bool
    {
        return $model instanceof ClaudeCode;
    }

    #[Override]
    public function request(Model $model, array|string $payload, array $options = []): RawResultInterface
    {
        new Filesystem()->mkdir($this->directory, 0o700);

        return $this->client()->request($model, $payload, $options);
    }

    // Built for each request because a worker lives on while variables are added to its environment, and each must reach the CLI emptied.
    private function client(): ModelClient
    {
        return new ModelClient(null, $this->directory, self::TIMEOUT, $this->environment());
    }

    /**
     * @return array<string, string>
     */
    private function environment(): array
    {
        // Process hands a child every inherited variable unless each is overridden by name: other providers' keys, the decryption secret and an API key that would win over the login. Emptied, not unset, because the bridge accepts strings only.
        $inherited = array_fill_keys(array_map(strval(...), array_keys([...getenv(), ...$_ENV])), '');

        return [...$inherited, 'PATH' => (string) getenv('PATH'), 'HOME' => $this->directory, 'CLAUDE_CODE_OAUTH_TOKEN' => $this->token];
    }
}
