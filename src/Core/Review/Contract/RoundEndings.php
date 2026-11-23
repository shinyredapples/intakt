<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

interface RoundEndings
{
    // One lookup per ledger read, because it reads the failure transport and the ledger once for every round it is asked about; it is never kept between reads.
    public function lookup(): RoundEndingLookup;
}
