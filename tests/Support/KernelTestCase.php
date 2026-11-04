<?php

declare(strict_types=1);

namespace Intakt\Tests\Support;

use Intakt\Tests\Support\Log\AssertsLoggedErrors;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase as SymfonyKernelTestCase;

// A base of its own, because the extension can only warn and #612 (D3) asks that the kernel test itself fails.
abstract class KernelTestCase extends SymfonyKernelTestCase
{
    use AssertsLoggedErrors;
}
