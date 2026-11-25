<?php

declare(strict_types=1);

namespace Intakt\Plugin\WorkSource\Contract;

// What an open task waits on in the way of a hand-over: a Maintainer session or a person, or nothing.
enum Holder
{
    case Session;
    case Person;
    case Nothing;
}
