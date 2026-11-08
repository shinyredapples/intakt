<?php

declare(strict_types=1);

namespace Intakt\Gates;

enum HighlightRowKind: string
{
    case TableRow = '|';
    case ListItem = '- ';

    public function noun(): string
    {
        return match ($this) {
            self::TableRow => 'table rows',
            self::ListItem => 'list items',
        };
    }
}
