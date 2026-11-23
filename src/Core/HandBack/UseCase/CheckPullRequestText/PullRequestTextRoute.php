<?php

declare(strict_types=1);

namespace Intakt\Core\HandBack\UseCase\CheckPullRequestText;

enum PullRequestTextRoute: string
{
    case SentBack = 'sent-back';
    case ToReviewers = 'to-reviewers';
}
