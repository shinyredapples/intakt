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
    // Used only while the line kinds are read, because an item whose first line is indented code opens a list but is measured as code.
    case CodeItem = 'code item';
    case Prose = 'prose';
}
