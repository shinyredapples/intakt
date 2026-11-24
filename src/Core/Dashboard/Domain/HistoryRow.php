<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

use DateTimeImmutable;
use DateTimeZone;
use Intakt\Core\Ledger\Contract\PayloadFields;
use Intakt\Core\Ledger\Contract\RecordedEvent;

final readonly class HistoryRow
{
    private const string UNNAMED_ACTOR = 'Intakt';

    // A change to a secret carries only its fingerprint, so before and after are not read from such a row and a secret's value can never reach the page.
    private function __construct(
        public DateTimeImmutable $at,
        public string $kind,
        public string $who,
        public ?string $project,
        public ?string $before,
        public ?string $after,
        public ?DateTimeImmutable $expires,
        public ?string $fingerprint,
    ) {
    }

    public static function of(RecordedEvent $recorded): self
    {
        $fields = PayloadFields::of($recorded);
        $fingerprint = $fields->text('fingerprint');
        $isSecret = null !== $fingerprint;

        return new self(
            $recorded->recordedAt->setTimezone(new DateTimeZone('UTC')),
            $recorded->event->kind->value,
            $fields->text('actor') ?? self::UNNAMED_ACTOR,
            $fields->text('project'),
            $isSecret ? null : $fields->text('before'),
            $isSecret ? null : $fields->text('after'),
            self::expiryOf($fields->text('expires_at')),
            $fingerprint,
        );
    }

    // The arrow is for a literal change, so it needs both values.
    public function showsChange(): bool
    {
        return null !== $this->before && null !== $this->after;
    }

    public function label(): string
    {
        return ucfirst(str_replace('_', ' ', $this->kind));
    }

    private static function expiryOf(?string $text): ?DateTimeImmutable
    {
        $expires = null === $text ? false : DateTimeImmutable::createFromFormat(DATE_ATOM, $text, new DateTimeZone('UTC'));

        return false === $expires ? null : $expires->setTimezone(new DateTimeZone('UTC'));
    }
}
