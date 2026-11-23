<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Domain;

use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\JudgedFinding;
use Intakt\Core\Review\Contract\Judgement;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// The judge only proposes a cause; plain code keeps a link only when both findings are in one hunk of one file and at most NEARBY_LINES apart, so a steered judge cannot fold a defect further away into another entry, even in a new file that is one long hunk. OnePerPlace holds the same bound for a whole group, because links that each pass here can still form a chain. A link it drops leaves the mark, so nothing is lost.
#[Exclude]
final readonly class CausesInOneHunk
{
    // One changed expression and the lines that read it; the merged entry keeps only the first finding's text, so the window stays this small.
    public const int NEARBY_LINES = 5;

    /**
     * @param list<Finding>                 $findings
     * @param list<array{string, int, int}> $hunks
     */
    public function checked(Judgement $judgement, array $findings, array $hunks): Judgement
    {
        $byId = array_combine(array_map(static fn (Finding $finding): string => $finding->id, $findings), $findings);

        return $judgement->withMarks(array_map(
            fn (JudgedFinding $judged): JudgedFinding => null === $judged->same_cause_as || $this->inOneHunk([$byId[$judged->id] ?? null, $byId[$judged->same_cause_as] ?? null], $hunks)
                ? $judged
                : new JudgedFinding($judged->id, $judged->mark, note: $judged->note),
            $judgement->marks,
        ));
    }

    /**
     * @param array{?Finding, ?Finding}     $pair
     * @param list<array{string, int, int}> $hunks
     */
    private function inOneHunk(array $pair, array $hunks): bool
    {
        [$one, $other] = $pair;
        if (null === $one?->file || null === $one->line || null === $other?->line || $one->file !== $other->file) {
            return false;
        }

        $lines = [$one->line, $other->line];
        if (max($lines) - min($lines) > self::NEARBY_LINES) {
            return false;
        }

        return array_any($hunks, static fn (array $hunk): bool => $hunk[0] === $one->file && $hunk[1] <= min($lines) && max($lines) <= $hunk[2]);
    }
}
