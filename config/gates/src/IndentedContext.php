<?php

declare(strict_types=1);

namespace Intakt\Gates;

enum IndentedContext: string
{
    case Text = 'text';
    case ListItem = 'list item';
    case Code = 'code';
}
