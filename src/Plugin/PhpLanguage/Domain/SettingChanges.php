<?php

declare(strict_types=1);

namespace Intakt\Plugin\PhpLanguage\Domain;

use Intakt\Plugin\Language\Contract\DependencyFinding;

// Every key besides the packages is one of Composer's own settings (scripts, plugins, repositories, config, autoloading, stability), which only the owner changes.
final readonly class SettingChanges
{
    /**
     * @return list<DependencyFinding>
     */
    public function between(ComposerState $before, ComposerState $after): array
    {
        return [
            ...$this->changed(ComposerState::MANIFEST, $before->manifestSettings, $after->manifestSettings),
            ...$this->changed(ComposerState::LOCK, $before->lockSettings, $after->lockSettings),
        ];
    }

    /**
     * @param array<array-key, mixed> $before
     * @param array<array-key, mixed> $after
     *
     * @return list<DependencyFinding>
     */
    private function changed(string $path, array $before, array $after): array
    {
        $findings = [];
        foreach (array_keys($before + $after) as $key) {
            if (($before[$key] ?? null) !== ($after[$key] ?? null) || array_key_exists($key, $before) !== array_key_exists($key, $after)) {
                $findings[] = new DependencyFinding(sprintf('%s %s', $path, $key), 'one of Composer\'s own settings changed');
            }
        }

        return $findings;
    }
}
