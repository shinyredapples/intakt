<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

use DomainException;

abstract class CheckoutPathRefused extends DomainException
{
}
