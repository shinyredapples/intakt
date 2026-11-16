<?php

declare(strict_types=1);

namespace Intakt\Tests\Support;

use Intakt\Tests\Support\Log\AssertsLoggedErrors;
use Symfony\Bundle\FrameworkBundle\Test\TestContainer;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase as SymfonyWebTestCase;
use Symfony\Component\Clock\ClockInterface;

// A base of its own, because the extension can only warn and #612 (D3) asks that the web test itself fails.
abstract class WebTestCase extends SymfonyWebTestCase
{
    use AssertsLoggedErrors;

    // Narrowed, because a helper handed this container may then read private services, which static analysis allows only through the test container.
    protected static function getContainer(): TestContainer
    {
        $container = parent::getContainer();
        assert($container instanceof TestContainer);

        return $container;
    }

    // Moves the container's clock instead of replacing it, because a clock something already created cannot be set again.
    protected static function clockAt(string $moment): void
    {
        $clock = self::getContainer()->get(ClockInterface::class);
        $clock->modify($moment);
    }
}
