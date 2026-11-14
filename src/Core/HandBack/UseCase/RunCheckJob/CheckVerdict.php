<?php

declare(strict_types=1);

namespace Intakt\Core\HandBack\UseCase\RunCheckJob;

// Each reason is fixed text, because the job ran model-written code and only its output directory says what happened.
enum CheckVerdict: string
{
    case Passed = 'passed';
    case Failed = 'failed';
    case LostContent = 'lost-content';
    case NotApplied = 'not-applied';
    case NoResult = 'no-result';
    case MemoryKilled = 'oom';
    case TimedOut = 'timed-out';

    public function reason(): string
    {
        return match ($this) {
            self::Passed => 'the check command passed',
            self::Failed => 'the check command failed',
            self::LostContent => 'a file lost most of its content, and the task\'s plan names it neither removed nor rewritten',
            self::NotApplied => 'the bundle could not be applied in a fresh clone of the mirror',
            self::NoResult => 'the job left no well-formed result in its output directory',
            self::MemoryKilled => 'the job was killed at its memory limit',
            self::TimedOut => 'the job ran out of time',
        };
    }
}
