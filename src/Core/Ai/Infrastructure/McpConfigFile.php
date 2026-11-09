<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Infrastructure;

use Symfony\Component\Filesystem\Filesystem;

// The CLI's MCP configuration holds the round's bearer credential, so it reaches the CLI as a file only its owner reads, never on the command line, which the bridge logs and every Process exception repeats.
final readonly class McpConfigFile
{
    private function __construct(
        public string $path,
    ) {
    }

    /**
     * @param array<string, mixed> $configuration
     */
    public static function in(string $directory, array $configuration): self
    {
        $files = new Filesystem();
        $files->mkdir($directory, 0o700);
        // A name of its own per call, because one account's reviews share the folder and may run at once.
        $path = $files->tempnam($directory, 'mcp-', '.json');
        $files->chmod($path, 0o600);
        $files->dumpFile($path, json_encode($configuration, JSON_THROW_ON_ERROR));

        return new self($path);
    }

    public function remove(): void
    {
        new Filesystem()->remove($this->path);
    }
}
