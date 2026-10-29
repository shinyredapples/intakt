<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Infrastructure;

use SensitiveParameter;
use Symfony\AI\Platform\Bridge\ClaudeCode\ModelClient;
use Symfony\AI\Platform\ModelClientInterface;
use Symfony\Component\Filesystem\Filesystem;

// The CLI runs in a directory of its own, so it never sees Intakt's install, its env files and secrets, or a checkout's settings and instructions.
final readonly class ClaudeCodeCliFactory
{
    private const float TIMEOUT = 300.0;

    public function __construct(
        private string $directory,
        #[SensitiveParameter]
        private string $token,
    ) {
    }

    public function create(): ModelClientInterface
    {
        new Filesystem()->mkdir($this->directory, 0o700);

        return new ModelClient(null, $this->directory, self::TIMEOUT, $this->environment());
    }

    /**
     * @return array<string, string|false>
     */
    private function environment(): array
    {
        // Process hands a child every inherited variable unless each is unset by name: other providers' keys, the decryption secret and an API key that would win over the login.
        $inherited = array_fill_keys(array_keys([...getenv(), ...$_ENV]), false);

        return [...$inherited, 'PATH' => (string) getenv('PATH'), 'HOME' => $this->directory, 'CLAUDE_CODE_OAUTH_TOKEN' => $this->token];
    }
}
