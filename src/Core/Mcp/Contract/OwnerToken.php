<?php

declare(strict_types=1);

namespace Intakt\Core\Mcp\Contract;

use SensitiveParameter;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

// The token the owner server accepts, read where the server's comes from: the variable, else the file the demo's entrypoint keeps. A process started by docker compose exec never ran the entrypoint, so it has the file but not the exported variable.
final readonly class OwnerToken
{
    private const string KEPT_FORMAT = '/^[0-9a-f]{64}$/';

    public function __construct(
        #[SensitiveParameter]
        #[Autowire(env: 'MCP_OWNER_TOKEN')]
        private string $configured,
        #[Autowire(env: 'default::MCP_OWNER_KEPT_FILE')]
        private ?string $keptFile,
    ) {
    }

    // Empty when there is no usable token, because the callers print a hint then; a kept file that holds anything but the entrypoint's 64 hex digits is no token.
    public function value(): string
    {
        if ('' !== $this->configured) {
            return $this->configured;
        }

        if (null === $this->keptFile || !is_file($this->keptFile)) {
            return '';
        }

        $kept = trim((string) file_get_contents($this->keptFile));

        return 1 === preg_match(self::KEPT_FORMAT, $kept) ? $kept : '';
    }
}
