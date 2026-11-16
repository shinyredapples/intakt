<?php

declare(strict_types=1);

namespace Intakt\Plugin\Vendor\Contract;

enum KeyCheckOutcome: string
{
    case Accepted = 'accepted';
    case Refused = 'refused';
    // The provider could not be asked or did not answer, so the key is neither good nor bad.
    case NotChecked = 'not_checked';
}
