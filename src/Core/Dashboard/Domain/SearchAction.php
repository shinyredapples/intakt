<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

enum SearchAction: string
{
    case SwitchTheme = 'switch-theme';
    case ClearSearch = 'clear-search';

    public function label(): string
    {
        return match ($this) {
            self::SwitchTheme => 'Switch light or dark theme',
            self::ClearSearch => 'Clear the search',
        };
    }

    public function result(): SearchResult
    {
        return new SearchResult(SearchGroup::Actions, $this->label(), 'All projects');
    }
}
