<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubWorkSource\Domain;

use RuntimeException;

// Apart from a failed read, because the item has to be read again on the next listing: the stored row still matches the plan, so every later tick would fail the same check.
final class ItemNotAsPlanned extends RuntimeException
{
    public static function listedOtherwise(int $number): self
    {
        return new self(sprintf('GitHub holds #%d with other labels, waits or closing references than it was planned with, so its history is not of the planned item.', $number));
    }

    public static function vouchedOtherwise(int $number): self
    {
        return new self(sprintf('The labels of #%d are no longer applied by the accounts with write access the plan counted, so it is not the planned item.', $number));
    }
}
