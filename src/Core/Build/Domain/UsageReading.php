<?php

declare(strict_types=1);

namespace Intakt\Core\Build\Domain;

// How reading a build's usage from its session log ended, because a log without usage, a refused log and a failed reader each leave the build's tokens unknown for another reason, never as zero.
enum UsageReading: string
{
    case Read = 'read';
    case NotReported = 'not_reported';
    case Malformed = 'malformed';
    case TooLarge = 'too_large';
    case LineTooLong = 'line_too_long';
    case ReaderFailed = 'reader_failed';

    // Only these, because the size is checked before the reader starts and its failure is seen from outside it, so any other answer did not come from the parser.
    public function canBeAnsweredByTheReader(): bool
    {
        return in_array($this, [self::Read, self::NotReported, self::Malformed, self::LineTooLong], true);
    }
}
