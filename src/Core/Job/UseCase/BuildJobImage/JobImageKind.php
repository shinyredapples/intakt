<?php

declare(strict_types=1);

namespace Intakt\Core\Job\UseCase\BuildJobImage;

enum JobImageKind: string
{
    case Project = 'project';
    case Review = 'review';
}
