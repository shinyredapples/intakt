<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Domain;

use DateTimeImmutable;
use SensitiveParameter;

final readonly class InstallationToken
{
    public function __construct(
        #[SensitiveParameter]
        public string $value,
        public DateTimeImmutable $expiresAt,
    ) {
    }
}
