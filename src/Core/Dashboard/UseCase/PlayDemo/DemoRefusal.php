<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\UseCase\PlayDemo;

use Intakt\Core\Dashboard\UseCase\RecordDemo\RecordingIsMalformed;
use Intakt\Core\Dashboard\UseCase\ReplayDemo\ReplayIsRefused;
use Intakt\Core\Dashboard\UseCase\ReplayDemo\ReplayStopped;
use Symfony\Component\Filesystem\Exception\IOException;
use Throwable;

// A fixed sentence per kind, because a failure's message can name the recording's absolute path and the page is no place for it; the log keeps the exception.
enum DemoRefusal
{
    case Unreadable;
    case Malformed;
    case Refused;
    case Stopped;
    case AnotherPlayFirst;
    case Busy;
    case CallUsedElsewhere;

    public static function of(Throwable $failure): self
    {
        return match (true) {
            $failure instanceof IOException => self::Unreadable,
            $failure instanceof RecordingIsMalformed => self::Malformed,
            $failure instanceof ReplayStopped => self::Stopped,
            $failure instanceof ReplayIsRefused && $failure->meansTheReplayLockIsBusy() => self::Busy,
            $failure instanceof ReplayIsRefused && $failure->meansAnotherPlayCameFirst() => self::AnotherPlayFirst,
            $failure instanceof ReplayIsRefused && $failure->meansAnotherReviewUsedACall() => self::CallUsedElsewhere,
            $failure instanceof ReplayIsRefused => self::Refused,
            default => throw $failure,
        };
    }

    public function sentence(): string
    {
        return match ($this) {
            self::Unreadable => 'The demo recording could not be read. Check INTAKT_DEMO_RECORDING and the install log.',
            self::Malformed => 'The demo recording is not a valid recording. The install log names the problem.',
            self::Refused => 'The replay was refused. The install log names the reason.',
            self::Stopped => 'The replay stopped before it ended. The install log names the reason.',
            self::AnotherPlayFirst => 'An earlier Play has already played the run. Follow it in the review list.',
            self::Busy => 'Another Play or a demo removal is running. Wait until it has ended, reload the page and press Play again.',
            self::CallUsedElsewhere => 'Another review in the ledger already used a model call of the demo recording, so the run was not played. The install log names the call.',
        };
    }
}
