<?php

declare(strict_types=1);

namespace Intakt\Core\HandBack\Contract;

// Each reason is fixed text, because a refused bundle is untrusted and none of its values may reach the ledger, a comment or the builder's feedback.
enum HandBackRefusal: string
{
    case Unreadable = 'unreadable';
    case TouchesMain = 'touches-main';
    case NamesAnotherRef = 'names-another-ref';
    case NotOnBranch = 'not-on-branch';
    case NotFastForward = 'not-fast-forward';
    case HoldsMerge = 'holds-merge';
    case MergesAnotherBranch = 'merges-another-branch';

    public function reason(): string
    {
        return match ($this) {
            self::Unreadable => 'the bundle cannot be read as a git bundle',
            self::TouchesMain => 'the bundle updates main',
            self::NamesAnotherRef => 'the bundle names a ref other than the task\'s branch',
            self::NotOnBranch => 'the bundle builds on a commit that is neither on the task\'s branch nor on main',
            self::NotFastForward => 'the bundle is not a fast-forward of the pushed branch',
            self::HoldsMerge => 'the bundle holds a merge commit, and no merge of main was asked',
            self::MergesAnotherBranch => 'a merge commit in the bundle merges something other than main',
        };
    }
}
