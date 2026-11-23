<?php

declare(strict_types=1);

namespace Intakt\Gates;

enum MarkdownLineKind: string
{
    case Blank = 'blank';
    case Heading = 'heading';
    case Break = 'break';
    case Table = 'table';
    case Code = 'code';
    case ListItem = 'list item';
    case Prose = 'prose';
}
