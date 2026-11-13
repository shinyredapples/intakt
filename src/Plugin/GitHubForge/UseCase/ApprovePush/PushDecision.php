<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\UseCase\ApprovePush;

enum PushDecision: string
{
    case Free = 'free';
    case Approved = 'approved';
    case Asked = 'asked';
    case StillAsked = 'still asked';

    public function letsItPush(): bool
    {
        return self::Free === $this || self::Approved === $this;
    }
}
