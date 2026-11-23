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
    case ToFix = 'fix';
    case Checked = 'checked';
    case ReviewPass = 'pass';

    public function explanation(): string
    {
        return match ($this) {
            self::Version => 'The exact commit Intakt built and checked. An approval covers this version only; a new push needs a new approval, unless it leaves what you approved exactly as it was (planned, for a limit like secrets: the same files, unchanged). A revised held CI push always needs its own approval.',
            self::PushOnHold => 'Intakt stopped this push before checks ran, because it changes something only you can allow, such as CI files or protected files.',
            self::WeeklyShare => "This project's part of the week's AI budget. Intakt paces work so spend follows it.",
            self::Round => 'One build, its checks and its review. Sending work back starts the next round.',
            self::ProtectedFiles => 'Files that always need your approval to change, such as secret handling or CI. You choose them per project.',
            self::Reserve => "The smallest part of the week's capacity kept for this project, even when other projects are busy.",
            self::ToFix => 'The act list: every P1 and P2 finding that passed the quote check, whatever the judge marked, and each P3 finding the judge kept, to fix before approval; a P3 gate gap goes to the follow-ups instead.',
            self::Checked => "Judged: the judge weighed every reviewer's findings and gave the verdict.",
            self::ReviewPass => 'A round: one review of the change, by its reviewers and then the judge.',
        };
    }
}
