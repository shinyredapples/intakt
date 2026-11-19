<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Infrastructure;

use Attribute;
use Symfony\Component\HttpKernel\Attribute\Lock;

// One key for every press that acts on a round, so a start and a stop of the same round, or two of either, run one after the other (#2703, D2); not blocking, because a worker should not wait for another press, and the controller answers a busy round with a line.
#[Attribute(Attribute::TARGET_METHOD)]
final class RoundLock extends Lock
{
    public const string KEY = 'dashboard-round-{round}';

    public function __construct()
    {
        parent::__construct(self::KEY);
    }
}
