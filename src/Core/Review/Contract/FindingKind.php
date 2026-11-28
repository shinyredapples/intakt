<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

enum FindingKind: string
{
    case Security = 'security';
    case Logic = 'logic';
    case MissingTest = 'missing test';
    case TidyUp = 'tidy-up';
    case Other = 'other';

    // One home for the words every reader shows beside the kind's symbol, so the live log, the summary and the cards name a kind alike.
    public function inWords(): string
    {
        return match ($this) {
            self::Security => 'Security',
            self::Logic => 'Logic',
            self::MissingTest => 'No test',
            self::TidyUp => 'Tidy-up',
            self::Other => 'Other',
        };
    }
}
