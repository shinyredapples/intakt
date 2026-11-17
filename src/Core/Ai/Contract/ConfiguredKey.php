<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Contract;

use SensitiveParameter;

final readonly class ConfiguredKey
{
    private const int SHOWN_AT_MOST = 4;

    public string $lastCharacters;

    // The limit is enforced here too, so a caller that passes a whole key still never exposes more than four characters.
    public function __construct(
        public string $provider,
        #[SensitiveParameter]
        string $lastCharacters,
    ) {
        $this->lastCharacters = substr($lastCharacters, -self::SHOWN_AT_MOST);
    }
}
