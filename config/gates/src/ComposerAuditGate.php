<?php

declare(strict_types=1);

namespace Intakt\Gates;

use JsonException;

final class ComposerAuditGate implements Gate
{
    private const string EVERY_VERSION = ' (every version)';

    public function name(): string
    {
        return 'audit';
    }

    public function run(Workspace $workspace): GateResult
    {
        $ignores = $this->auditConfiguration($workspace);

        if ([] !== $ignores) {
            return GateResult::withFindings($this->name(), $ignores);
        }

        $result = $this->audit($workspace);

        if (!$result instanceof ProcessResult) {
            return GateResult::failedBecause($this->name(), 'cannot create a scratch COMPOSER_HOME for the audit');
        }

        if ($result->succeeded()) {
            return $this->passedNaming($this->cooldownExceptions($workspace));
        }

        $findings = $this->findings($result->output);

        return GateResult::withFindings($this->name(), [] === $findings ? ['composer audit failed: '.$result->firstErrorLine()] : $findings);
    }

    /**
     * @param list<string> $exceptions
     */
    private function passedNaming(array $exceptions): GateResult
    {
        if ([] === $exceptions) {
            return GateResult::passed($this->name());
        }

        return GateResult::passedBecause($this->name(), 'cooldown exceptions in force: '.implode(', ', $exceptions));
    }

    private function audit(Workspace $workspace): ?ProcessResult
    {
        $home = ScratchDirectory::create('intakt-composer-home');

        if (!$home instanceof ScratchDirectory) {
            return null;
        }

        try {
            return $workspace->command(
                ['composer', 'audit', '--locked', '--abandoned=fail', '--format=json', '--no-interaction'],
                ['COMPOSER_HOME' => $home->path, 'COMPOSER_AUDIT_ABANDONED' => 'fail'],
            );
        } finally {
            $home->remove();
        }
    }

    /**
     * @return list<string>
     */
    private function auditConfiguration(Workspace $workspace): array
    {
        $path = $workspace->root.'/composer.json';
        $manifest = is_file($path) ? JsonNode::parse((string) file_get_contents($path)) : null;

        if (!$manifest instanceof JsonNode) {
            return [];
        }

        return array_map(
            static fn (string $setting): string => 'composer.json config.audit.'.$setting.' hides findings from the audit',
            array_keys($manifest->get('config')->get('audit')->entries()),
        );
    }

    /**
     * @return list<string>
     */
    private function cooldownExceptions(Workspace $workspace): array
    {
        $exceptions = [];

        // The shapes follow Composer's IgnorePackageRule::parseIgnoreMap; Composer itself rejects any other shape.
        foreach ($this->cooldownIgnoreMap($workspace) as $key => $value) {
            if (is_int($key)) {
                $exceptions[] = is_string($value) ? $value.self::EVERY_VERSION : null;

                continue;
            }

            if (null === $value || is_string($value)) {
                $exceptions[] = $key.self::EVERY_VERSION;

                continue;
            }

            foreach (is_array($value) && isset($value[0]) ? $value : [$value] as $rule) {
                $exceptions[] = $this->auditException($key, $rule);
            }
        }

        return array_values(array_filter($exceptions, static fn (?string $exception): bool => null !== $exception));
    }

    private function auditException(string $package, mixed $rule): ?string
    {
        if (!is_array($rule) || false === ($rule['on-audit'] ?? true)) {
            return null;
        }

        $constraint = $rule['constraint'] ?? null;

        return is_scalar($constraint) && '' !== (string) $constraint ? $package.' '.$constraint : $package.self::EVERY_VERSION;
    }

    /**
     * @return array<mixed>
     */
    private function cooldownIgnoreMap(Workspace $workspace): array
    {
        $path = $workspace->root.'/composer.json';

        try {
            $node = is_file($path) ? json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR) : null;
        } catch (JsonException) {
            return [];
        }

        foreach (['config', 'policy', 'cooldown', 'ignore'] as $key) {
            $node = is_array($node) ? $node[$key] ?? null : null;
        }

        return is_array($node) ? $node : [];
    }

    /**
     * @return list<string>
     */
    private function findings(string $output): array
    {
        $report = JsonNode::parse($output);

        if (!$report instanceof JsonNode) {
            return [];
        }

        $findings = [];

        foreach ($report->get('advisories')->entries() as $package => $advisories) {
            foreach ($advisories->items() as $advisory) {
                $findings[] = $package.' '.$advisory->get('advisoryId')->text().' '.$advisory->get('title')->text();
            }
        }

        foreach ($report->get('abandoned')->entries() as $package => $replacement) {
            $findings[] = $package.' is abandoned, replacement: '.('' === $replacement->text() ? 'none' : $replacement->text());
        }

        foreach ($report->get('cooldown')->entries() as $package => $release) {
            $findings[] = $package.' '.$release->get('prettyVersion')->text().' published '.$release->get('releaseDate')->text()
                .' is in the cooldown, available in '.$release->get('availableIn')->text();
        }

        return $findings;
    }
}
