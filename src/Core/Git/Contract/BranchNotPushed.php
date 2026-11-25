<?php

declare(strict_types=1);

namespace Intakt\Core\Git\Contract;

use Intakt\Core\Platform\Contract\ReportableFailure;
use RuntimeException;
use Throwable;

final class BranchNotPushed extends RuntimeException implements ReportableFailure
{
    public private(set) RemoteWrite $write = RemoteWrite::None;

    public static function because(string $reason, ?Throwable $previous = null, RemoteWrite $write = RemoteWrite::None): self
    {
        $failure = new self('The branch was not pushed: '.$reason, 0, $previous);
        $failure->write = $write;

        return $failure;
    }

    // A copy and not a change of this failure, because a git call's failure knows nothing of the push it is part of, and only the push knows whether git push had run by then.
    public function after(RemoteWrite $write): self
    {
        $failure = new self($this->getMessage(), 0, $this);
        $failure->write = $write;

        return $failure;
    }
}
