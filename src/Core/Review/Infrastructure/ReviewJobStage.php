<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure;

// Kept apart, because a CLI that ran may have spent tokens even when it reported none, and a job that failed before spent none.
enum ReviewJobStage: string
{
    case BeforeTheCli = 'before_the_cli';
    case AfterTheCli = 'after_the_cli';
}
