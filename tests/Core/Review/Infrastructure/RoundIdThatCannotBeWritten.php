<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Infrastructure;

use LogicException;
use Override;
use Symfony\Component\Uid\UuidV7;

// A round whose id fails while the credential is issued, so a test can raise a failure inside the narrowed catch.
final class RoundIdThatCannotBeWritten extends UuidV7
{
    public function __construct(private readonly LogicException $unexpected)
    {
        parent::__construct();
    }

    #[Override]
    public function toRfc4122(): string
    {
        throw $this->unexpected;
    }
}
