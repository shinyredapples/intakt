<?php

declare(strict_types=1);

namespace Intakt\Core\HandBack\Contract;

// Each reason is fixed text, because the pull request text is the builder's and must not choose the words of its own refusal.
enum PullRequestTextVerdict: string
{
    case Passed = 'passed';
    case MissingEvidenceReference = 'missing-evidence-reference';
    case BodyOverCap = 'body-over-cap';

    public function reason(): string
    {
        return match ($this) {
            self::Passed => 'the pull request text references every top-level Evidence item of the task and its body is within the cap',
            self::MissingEvidenceReference => 'the pull request text does not reference every top-level Evidence item of the task',
            self::BodyOverCap => 'the pull request body is over the cap',
        };
    }
}
