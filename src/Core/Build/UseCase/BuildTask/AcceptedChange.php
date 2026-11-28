<?php

declare(strict_types=1);

namespace Intakt\Core\Build\UseCase\BuildTask;

use Intakt\Core\HandBack\Contract\AcceptedHandBack;
use Intakt\Plugin\Forge\Contract\ChangeText;

// The head the hand-back check accepted with the text it goes to the forge with, because the push and the pull request steps both carry exactly this pair.
final readonly class AcceptedChange
{
    public function __construct(
        public AcceptedHandBack $accepted,
        public ChangeText $text,
    ) {
    }
}
