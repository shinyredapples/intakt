<?php

declare(strict_types=1);

namespace Intakt\Plugin\WorkSource\Contract;

// A hand-over is offered to a Maintainer session or a person, because the report ranks what each of them holds.
enum Holder
{
    case Session;
    case Person;
    case Nothing;
}
