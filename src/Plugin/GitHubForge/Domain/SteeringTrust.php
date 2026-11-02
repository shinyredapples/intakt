<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Domain;

// Each reason is kept apart, so the issue can say why a label or comment was ignored.
enum SteeringTrust: string
{
    case Writer = 'writer';
    case OwnIdentity = 'own-identity';
    case Maintainer = 'maintainer';
    case NonWriter = 'non-writer';
    case ForeignBot = 'foreign-bot';

    public function steers(): bool
    {
        return match ($this) {
            self::Writer, self::OwnIdentity, self::Maintainer => true,
            self::NonWriter, self::ForeignBot => false,
        };
    }
}
