<?php

declare(strict_types=1);

namespace Intakt\Plugin\PhpLanguage\Domain;

use Intakt\Plugin\Language\Contract\DependencyFinding;

// The requirements of composer.json and the stability flags the lock file keeps for them.
final readonly class RequirementChanges
{
    // In any case, because Composer reads a stability flag in any case.
    private const string FLAG = '/@(stable|rc|beta|alpha|dev)\b/i';

    // Only for a package the same change adds, because a pre-release of a new package is routine and a development build is not.
    private const array ROUTINE_FLAGS = ['stable', 'rc', 'beta'];

    // As numbers, because the lock file writes RC as 5 and beta as 10 (Composer's BasePackage::STABILITIES).
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
        $findings = [];
        foreach ($this->names(array_diff_key($after->requirements, $before->requirements)) as $name) {
            if (!$this->trusted->trusts($name)) {
                $findings[] = new DependencyFinding($name, 'is not from a trusted vendor');
            } elseif (!array_key_exists($name, $after->locked)) {
                // Only the lock entry names its repository, tag, type and autoloading, which the package rules check.
                $findings[] = new DependencyFinding($name, 'is not locked in composer.lock');
            }
        }

        return $findings;
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
            if ($earlier['section'] !== $requirement['section'] && (!$this->trusted->trusts($name) || $earlier['constraint'] !== $requirement['constraint'])) {
                $findings[] = new DependencyFinding($name, sprintf('moved from %s to %s', $earlier['section'], $requirement['section']));
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
                $findings[] = new DependencyFinding($name, sprintf('has the stability flag @%s', implode(', @', $extra)));
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
                $findings[] = new DependencyFinding($name, 'its stability flag in composer.lock changed');
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
