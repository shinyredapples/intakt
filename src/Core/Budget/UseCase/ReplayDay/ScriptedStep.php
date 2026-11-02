<?php

declare(strict_types=1);

namespace Intakt\Core\Budget\UseCase\ReplayDay;

use DateTimeImmutable;
use DateTimeZone;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final readonly class ScriptedStep
{
    private const string MOMENT_FORMAT = 'Y-m-d\TH:i';

    /**
     * @param list<string> $words
     */
    private function __construct(
        public string $line,
        public DateTimeImmutable $at,
        public array $words,
    ) {
    }

    /**
     * @throws ScriptLineNotUnderstood
     */
    public static function parse(string $line): self
    {
        $words = preg_split('/\s+/', trim($line)) ?: [];
        $at = self::momentOf(array_shift($words) ?? '', $line);

        return new self(trim($line), $at, $words);
    }

    /**
     * @throws ScriptLineNotUnderstood
     */
    public static function momentOf(string $text, string $line): DateTimeImmutable
    {
        $moment = DateTimeImmutable::createFromFormat('!'.self::MOMENT_FORMAT, $text, new DateTimeZone('UTC'));

        // Read back and compared, because createFromFormat rolls a 25:00 over into the next day instead of refusing it.
        if (!$moment instanceof DateTimeImmutable || $moment->format(self::MOMENT_FORMAT) !== $text) {
            throw ScriptLineNotUnderstood::because($line, 'a moment is written as 2026-11-02T08:00 in UTC');
        }

        return $moment;
    }
}
