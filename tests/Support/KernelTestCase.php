<?php

declare(strict_types=1);

namespace Intakt\Tests\Support;

use Intakt\Tests\Support\Log\AssertsLoggedErrors;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase as SymfonyKernelTestCase;

// The extension can only warn about an unexpected error; this base fails the kernel test itself (#612, D3).
abstract class KernelTestCase extends SymfonyKernelTestCase
{
    use AssertsLoggedErrors;
}
