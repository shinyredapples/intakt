<?php

declare(strict_types=1);

namespace Intakt\Tests\Support;

use Intakt\Tests\Support\Log\AssertsLoggedErrors;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase as SymfonyWebTestCase;

// The extension can only warn about an unexpected error; this base fails the web test itself (#612, D3).
abstract class WebTestCase extends SymfonyWebTestCase
{
    use AssertsLoggedErrors;
}
