<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\UseCase\ShowReview;

// The review's two views of one template (owner decision for #3360), an enum because the flag gate refuses a boolean mode.
enum ReviewView
{
    case Main;
    case FullRecord;
}
