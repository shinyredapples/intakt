<?php

declare(strict_types=1);

namespace Intakt\Core\Watchdog\Infrastructure;

use DateTimeInterface;
use Intakt\Core\Watchdog\Contract\MainViolation;
use Intakt\Core\Watchdog\Contract\OwnerCard;

// One home for the watchdog's console lines, because every door prints text that git, a path or a caller supplied.
final readonly class ConsoleReport
{
    private const string UNICODE_CONTROLS = '/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]/u';

    public static function printable(string $text): string
    {
        // Because a path or git's output can hold a newline or an escape sequence, which would forge a line of this report or steer the terminal; the error block escapes formatter tags itself.
        $escaped = addcslashes($text, "\0..\37\177\\");

        // Unicode's own controls and line breaks (a next line, a line separator, a direction override) do the same; text that is not UTF-8 has every byte above ASCII escaped, because no character can be told apart in it.
        return preg_replace_callback(self::UNICODE_CONTROLS, static fn (array $match): string => sprintf('\u{%04X}', mb_ord($match[0], 'UTF-8')), $escaped) ?? addcslashes($escaped, "\200..\377");
    }

    public static function cardLine(OwnerCard $card): string
    {
        $raised = $card->raisedAt->format(DateTimeInterface::ATOM);
        if (!$card->violation instanceof MainViolation) {
            return sprintf('card %s halts every project: its halt record is unreadable, raised %s', $card->id->toRfc4122(), $raised);
        }

        return sprintf('card %s halts %s: commit %s %s, raised %s', $card->id->toRfc4122(), self::printable($card->project), $card->violation->commit, $card->violation->violation->value, $raised);
    }
}
