<?php

declare(strict_types=1);

namespace Intakt\Tests\Application;

use Closure;
use Symfony\Component\HttpKernel\Event\RequestEvent;

// Declared in the container, because Symfony 8.2 deprecates adding a listener to the compiled dispatcher at runtime; a request without the attribute runs nothing.
final readonly class RunRequestStep
{
    public const string ATTRIBUTE = '_intakt_test_step';

    public function __invoke(RequestEvent $event): void
    {
        $step = $event->getRequest()->attributes->get(self::ATTRIBUTE);

        if ($step instanceof Closure) {
            $step();
        }
    }
}
