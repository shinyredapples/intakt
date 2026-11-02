<?php

declare(strict_types=1);

namespace Intakt\Core\Shadow\UseCase\ShadowReport;

enum ReportFormat: string
{
    case Table = 'table';
    case Json = 'json';
}
