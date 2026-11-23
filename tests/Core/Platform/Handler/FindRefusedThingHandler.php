<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Platform\Handler;

use Intakt\Core\Platform\Contract\AsQueryHandler;
use Symfony\Component\DependencyInjection\Attribute\When;

// The handler attribute follows another in one group, so the marker test proves its scan finds that form too.
#[When(env: 'test'), AsQueryHandler]
final class FindRefusedThingHandler
{
    public function __invoke(FindRefusedThing $query): never
    {
        throw $query->refusal;
    }
}
