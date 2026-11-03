<?php

declare(strict_types=1);

namespace Intakt\Gates;

enum MarkdownBlock: string
{
    case Text = 'text';
    case Fence = 'fence';
    case Comment = 'comment';
}
