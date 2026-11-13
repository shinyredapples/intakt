<?php

declare(strict_types=1);

namespace Intakt\Plugin\PhpLanguage\Domain;

use Intakt\Plugin\Language\Contract\DependencyFinding;

// The requirements of composer.json and the stability flags the lock file keeps for them.
final readonly class RequirementChanges
{
    // A constraint's stability flags, in any case, as Composer reads them.
    private const string FLAG = '/@(stable|rc|beta|alpha|dev)\b/i';

    // Flags a package the same change adds may carry: a pre-release of a new package is routine, a development build is not.
    private const array ROUTINE_FLAGS = ['stable', 'rc', 'beta'];

    // How the lock file writes them: RC is 5 and beta is 10 in Composer's BasePackage::STABILITIES.
    private const array ROUTINE_LOCK_FLAGS = [5, 10];

    public function __construct(
        private TrustedPackages $trusted,
    ) {
    }

    /**
     * @return list<DependencyFinding>
     */
    public function between(ComposerState $before, ComposerState $after): array
    {
        return [
            ...$this->removed($before, $after),
            ...$this->added($before, $after),
            ...$this->moved($before, $after),
            ...$this->flagged($before, $after),
            ...$this->lockFlags($before, $after),
        ];
    }

    /**
     * @return list<DependencyFinding>
     */
    private function removed(ComposerState $before, ComposerState $after): array
    {
        return array_map(
            static fn (string $name): DependencyFinding => new DependencyFinding($name, 'was removed from composer.json'),
            $this->names(array_diff_key($before->requirements, $after->requirements)),
        );
    }

    /**
     * @return list<DependencyFinding>
     */
    private function added(ComposerState $before, ComposerState $after): array
    {
        $untrusted = array_filter($this->names(array_diff_key($after->requirements, $before->requirements)), fn (string $name): bool => !$this->trusted->trusts($name));

        return array_values(array_map(static fn (string $name): DependencyFinding => new DependencyFinding($name, 'is not from a trusted vendor'), $untrusted));
    }

    /**
     * @return list<DependencyFinding>
     */
    private function moved(ComposerState $before, ComposerState $after): array
    {
        $findings = [];
        foreach (array_intersect_key($after->requirements, $before->requirements) as $name => $requirement) {
            $earlier = $before->requirements[$name];

            // Only a trusted package that keeps its constraint moves without the owner, so a move cannot hide a new version of another vendor's package.
            if ($earlier['section'] !== $requirement['section'] && (!$this->trusted->trusts((string) $name) || $earlier['constraint'] !== $requirement['constraint'])) {
                $findings[] = new DependencyFinding((string) $name, sprintf('moved from %s to %s', $earlier['section'], $requirement['section']));
            }
        }

        return $findings;
    }

    /**
     * @return list<DependencyFinding>
     */
    private function flagged(ComposerState $before, ComposerState $after): array
    {
        $findings = [];
        foreach ($after->requirements as $name => $requirement) {
            $allowed = array_key_exists($name, $before->requirements) ? $this->flagsOf($before->requirements[$name]['constraint']) : self::ROUTINE_FLAGS;
            $extra = array_diff($this->flagsOf($requirement['constraint']), $allowed, ['stable']);

            if ([] !== $extra) {
                $findings[] = new DependencyFinding((string) $name, sprintf('has the stability flag @%s', implode(', @', $extra)));
            }
        }

        return $findings;
    }

    /**
     * @return list<DependencyFinding>
     */
    private function lockFlags(ComposerState $before, ComposerState $after): array
    {
        $findings = [];
        foreach ($after->stabilityFlags as $name => $flag) {
            $unchanged = array_key_exists($name, $before->stabilityFlags) && $before->stabilityFlags[$name] === $flag;
            $routineForNew = !array_key_exists($name, $before->requirements) && array_key_exists($name, $after->requirements) && in_array($flag, self::ROUTINE_LOCK_FLAGS, true);

            if (!$unchanged && !$routineForNew) {
                $findings[] = new DependencyFinding((string) $name, 'its stability flag in composer.lock changed');
            }
        }

        return $findings;
    }

    /**
     * @return list<string>
     */
    private function flagsOf(string $constraint): array
    {
        preg_match_all(self::FLAG, $constraint, $matches);

        return array_values(array_unique(array_map(strtolower(...), $matches[1])));
    }

    /**
     * @param array<array-key, mixed> $byName
     *
     * @return list<string>
     */
    private function names(array $byName): array
    {
        return array_map(strval(...), array_keys($byName));
    }
}
