<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Infrastructure;

use Intakt\Core\Ai\Contract\ReviewServerAccess;
use Override;
use SensitiveParameter;
use Symfony\AI\Platform\Bridge\ClaudeCode\ClaudeCode;
use Symfony\AI\Platform\Bridge\ClaudeCode\ModelClient;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\ModelClientInterface;
use Symfony\AI\Platform\Result\RawResultInterface;
use Symfony\Component\Filesystem\Filesystem;
use Throwable;

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

        $access = $options[ReviewServerAccess::OPTION] ?? null;
        unset($options[ReviewServerAccess::OPTION]);

        if (!$access instanceof ReviewServerAccess) {
            return $this->client()->request($model, $payload, $options);
        }

        $configuration = $this->configurationFileFor($access);

        try {
            $run = $this->client()->request($model, $payload, [...$options, 'mcp_config' => $configuration]);
            // Boundary: removes the bearer file on any failure to start the run and rethrows, because no run will read it.
        } catch (Throwable $notStarted) {
            new Filesystem()->remove($configuration);

            throw $notStarted;
        }

        return new RemovedAfterRun($run, $configuration);
    }

    // Built for each request because a worker lives on while variables are added to its environment, and each must reach the CLI emptied.
    private function client(): ModelClient
    {
        return new ModelClient(null, $this->directory, self::TIMEOUT, $this->environment());
    }

    // A file and never the flag's inline JSON, because Process puts the command line into its timeout message, which is logged; one file per run, so runs of other rounds never share one.
    private function configurationFileFor(ReviewServerAccess $access): string
    {
        $files = new Filesystem();
        $file = $files->tempnam($this->directory, 'review-server-', '.json');
        // Still empty here, so the bearer is never readable by anyone else.
        $files->chmod($file, 0o600);
        $files->appendToFile($file, json_encode($access->mcpConfiguration(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return $file;
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
