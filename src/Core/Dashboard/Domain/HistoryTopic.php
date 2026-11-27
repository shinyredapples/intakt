<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

use Intakt\Core\Ledger\Contract\LedgerEventKind;

// Declared in the order of docs/design/mocks/history.html, because the History page renders its chips in case order.
enum HistoryTopic: string
{
    case Limits = 'limits';
    case Projects = 'projects';
    case Accounts = 'accounts';

    /**
     * @return list<self>
     */
    public static function of(LedgerEventKind $kind, ?string $project): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $topic): bool => $topic->covers($kind, $project),
        ));
    }

    public function filterLabel(): string
    {
        return ucfirst($this->value);
    }

    // A cooled-down account waits out its provider's limit, so it is both a limit and an account change; any row that names a project is that project's.
    private function covers(LedgerEventKind $kind, ?string $project): bool
    {
        return match ($this) {
            self::Limits => LedgerEventKind::AccountCooledDown === $kind,
            self::Projects => null !== $project,
            self::Accounts => in_array($kind, [LedgerEventKind::AccountCooledDown, LedgerEventKind::AccountErrorReported], true),
        };
    }
}
