<?php

declare(strict_types=1);

namespace Intakt\Core\Watchdog\Contract;

enum Verdict: string
{
    case RecordedMerge = 'recorded_merge';
    case OwnerSigned = 'owner_signed';
    case Unsigned = 'unsigned';
    case UnknownKey = 'unknown_key';
    case HistoryRewritten = 'history_rewritten';

    public function isViolation(): bool
    {
        return match ($this) {
            self::RecordedMerge, self::OwnerSigned => false,
            self::Unsigned, self::UnknownKey, self::HistoryRewritten => true,
        };
    }

    public static function ofSignature(SignatureStatus $status): self
    {
        return match ($status) {
            SignatureStatus::Good => self::OwnerSigned,
            SignatureStatus::Unsigned => self::Unsigned,
            SignatureStatus::UnknownKey => self::UnknownKey,
        };
    }
}
