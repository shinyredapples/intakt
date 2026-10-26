<?php

declare(strict_types=1);

namespace Intakt\Gates;

enum Mode
{
    case Full;
    case ChangedFilesOnly;
}
