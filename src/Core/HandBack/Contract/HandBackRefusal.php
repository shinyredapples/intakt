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
    case Incomplete = 'incomplete';
    case NotFastForward = 'not-fast-forward';
    case HoldsMerge = 'holds-merge';
    case MergesAnotherBranch = 'merges-another-branch';
    case Malformed = 'malformed';
    case HoldsCredential = 'holds-credential';
    case HoldsSecret = 'holds-secret';

    public function reason(): string
    {
        return match ($this) {
            self::Unreadable => 'the bundle cannot be read as a git bundle',
            self::TouchesMain => 'the bundle updates main',
            self::NamesAnotherRef => 'the bundle names a ref other than the task\'s branch',
            self::NotOnBranch => 'the bundle builds on a commit that is neither on the task\'s branch nor on main',
            self::Incomplete => 'the bundle leaves out objects its commits need',
            self::NotFastForward => 'the bundle is not a fast-forward of the pushed branch, or does not start on main before the first push',
            self::HoldsMerge => 'the bundle holds a merge commit, and no merge of main was asked',
            self::MergesAnotherBranch => 'a merge commit in the bundle merges something other than main',
            self::Malformed => 'the bundle holds a commit git would not write',
            self::HoldsCredential => 'a file or file name the bundle adds holds the value of a credential mounted into the build job',
            self::HoldsSecret => 'the secret scan finds a secret in a commit, message or file the bundle adds',
        };
    }
}
