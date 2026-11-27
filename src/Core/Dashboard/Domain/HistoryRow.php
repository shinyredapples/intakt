<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

use DateTimeImmutable;
use DateTimeZone;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\PayloadFields;
use Intakt\Core\Ledger\Contract\RecordedEvent;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final readonly class HistoryRow
{
    private const string UNNAMED_ACTOR = 'Intakt';
    private const string DASHBOARD_ACTOR = 'Dashboard';

    // A change to a secret carries only its fingerprint, so before and after are not read from such a row and a secret's value can never reach the page.
    private function __construct(
        public DateTimeImmutable $at,
        public string $kind,
        public string $who,
        public HistorySource $source,
        public ?string $project,
        public ?string $round,
        public ?string $before,
        public ?string $after,
        public ?DateTimeImmutable $expires,
        public ?string $fingerprint,
        private bool $namesProject,
    ) {
    }

    public static function of(RecordedEvent $recorded): self
    {
        $fields = PayloadFields::of($recorded);
        $fingerprint = $fields->text('fingerprint');
        $isSecret = null !== $fingerprint;
        $actor = self::filled($fields->text('actor'));
        $source = self::sourceOf($actor, $recorded->event->kind);

        return new self(
            $recorded->recordedAt->setTimezone(new DateTimeZone('UTC')),
            $recorded->event->kind->value,
            $actor ?? self::unnamedActorOf($source),
            $source,
            self::filled($fields->text('project')),
            $fields->text('round'),
            $isSecret ? null : $fields->text('before'),
            $isSecret ? null : $fields->text('after'),
            self::expiryOf($fields->text('expires_at') ?? $fields->text('until')),
            $fingerprint,
            null !== $fields->text('project'),
        );
    }

    // A reviewer's or the judge's event names only its round, so its project is the one the round's start names. An event that wrote an empty project said it has none, so it keeps none.
    /**
     * @param array<string, string> $projectOfRound
     */
    public function inProjectOf(array $projectOfRound): self
    {
        if ($this->namesProject || null === $this->round || !isset($projectOfRound[$this->round])) {
            return $this;
        }

        return clone ($this, ['project' => $projectOfRound[$this->round]]);
    }

    // The arrow is for a literal change, so it needs two values that differ.
    public function showsChange(): bool
    {
        return null !== $this->before && null !== $this->after && $this->before !== $this->after;
    }

    public function label(): string
    {
        return ucfirst(str_replace('_', ' ', $this->kind));
    }

    /**
     * @return list<HistoryTopic>
     */
    public function topics(): array
    {
        return HistoryTopic::of(LedgerEventKind::from($this->kind), $this->project);
    }

    // A name is set only when it holds a visible character: a letter, number, punctuation or symbol. A name of only white space, format characters or lone combining marks (a form feed, a no-break space, a zero-width space, U+034F, U+FE0F) is no name and reads as null, so the topic, the project filter and the Source column all agree; a name that holds such a character is kept exactly as written.
    private static function filled(?string $text): ?string
    {
        return null === $text || 1 !== preg_match('/[\p{L}\p{N}\p{P}\p{S}]/u', $text) ? null : $text;
    }

    // Only the dashboard's Stop button writes a reviewer stop request, and that row names no actor, so its kind alone says where it came from.
    private static function sourceOf(?string $actor, LedgerEventKind $kind): HistorySource
    {
        return null !== $actor || LedgerEventKind::ReviewerStopRequested === $kind ? HistorySource::Dashboard : HistorySource::Intakt;
    }

    // A row without an actor names where it came from, so the dashboard's Stop, listed under From the dashboard, does not say Intakt in its Who.
    private static function unnamedActorOf(HistorySource $source): string
    {
        return HistorySource::Dashboard === $source ? self::DASHBOARD_ACTOR : self::UNNAMED_ACTOR;
    }

    // createFromFormat() rolls an impossible date such as February 30 over into March and only warns, so a warning makes the expiry unreadable. It throws on a NUL byte, which would take the whole page down for one row.
    private static function expiryOf(?string $text): ?DateTimeImmutable
    {
        if (null === $text || str_contains($text, "\0")) {
            return null;
        }

        $expires = DateTimeImmutable::createFromFormat(DATE_ATOM, $text, new DateTimeZone('UTC'));

        return false === $expires || false !== DateTimeImmutable::getLastErrors() ? null : $expires->setTimezone(new DateTimeZone('UTC'));
    }
}
