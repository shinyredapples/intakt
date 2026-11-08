<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

// One home for each standard explanation, so no page writes a different one (docs/design/mocks/DESIGN.md, Glossary).
enum GlossaryTerm: string
{
    case Version = 'version';
    case PushOnHold = 'hold';
    case WeeklyShare = 'share';
    case Round = 'round';
    case ProtectedFiles = 'protected';
    case Reserve = 'reserve';

    public function explanation(): string
    {
        return match ($this) {
            self::Version => 'The exact commit Intakt built and checked. An approval covers this version only; a new push needs a new approval.',
            self::PushOnHold => 'Intakt stopped this push before checks ran, because it changes something only you can allow, such as CI files or protected files.',
            self::WeeklyShare => "This project's part of the week's AI budget. Intakt paces work so spend follows it.",
            self::Round => 'One build, its checks and its review. Sending work back starts the next round.',
            self::ProtectedFiles => 'Files that always need your approval to change, such as secret handling or CI. You choose them per project.',
            self::Reserve => "The smallest part of the week's capacity kept for this project, even when other projects are busy.",
        };
    }
}
