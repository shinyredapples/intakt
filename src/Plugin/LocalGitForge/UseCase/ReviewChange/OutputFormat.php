<?php

declare(strict_types=1);

namespace Intakt\Plugin\LocalGitForge\UseCase\ReviewChange;

enum OutputFormat: string
{
    case Text = 'text';
    case Json = 'json';
}
