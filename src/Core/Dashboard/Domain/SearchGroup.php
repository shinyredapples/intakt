<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

// Kept in this order because SearchAnswer shows the groups in case order, the order of the search mock.
enum SearchGroup: string
{
    case Recent = 'Recent';
    case Actions = 'Actions';
    case Reviews = 'Reviews';
    case Projects = 'Projects';
    case Pages = 'Pages';

    public function icon(): string
    {
        return match ($this) {
            self::Recent => 'clock',
            self::Actions => 'more',
            self::Reviews => 'pr',
            self::Projects => 'projects',
            self::Pages => 'link',
        };
    }
}
