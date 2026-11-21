<?php

declare(strict_types=1);

namespace Intakt\Gates;

// Because only a line the child printed itself may be replaced by the whole result it kept, while a line bin/check built names why the child stopped.
enum GateLine: string
{
    case Printed = 'printed';
    case Built = 'built';
}
