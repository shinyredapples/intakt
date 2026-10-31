<?php

declare(strict_types=1);

namespace Intakt\Plugin\Forge\Contract;

enum HeadMoveKind: string
{
    case Unmoved = 'unmoved';
    case CleanMergeOfBase = 'clean-merge-of-base';
    case Commits = 'commits';
    case Rewritten = 'rewritten';
}
