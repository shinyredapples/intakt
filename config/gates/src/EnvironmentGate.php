<?php

declare(strict_types=1);

namespace Intakt\Gates;

final readonly class EnvironmentGate implements Gate
{
    private const string MANIFEST = 'composer.json';
    private const string CHECK_INI = 'config/php/check.ini';

    public function __construct(private PhpRuntime $runtime)
    {
    }

    public function name(): string
    {
        return 'environment';
    }

    public function run(Workspace $workspace): GateResult
    {
        $manifest = $this->manifest($workspace);
        $settings = $this->settings($workspace);

        if (!$manifest instanceof JsonNode) {
            return GateResult::failedBecause($this->name(), self::MANIFEST.' is missing or not valid JSON');
        }

        if (null === $settings) {
            return GateResult::failedBecause($this->name(), self::CHECK_INI.' is missing or not valid');
        }

        $findings = [...$this->versionFindings($manifest), ...$this->extensionFindings($manifest), ...$this->settingFindings($settings)];

        return [] === $findings ? GateResult::passed($this->name()) : GateResult::withFindings($this->name(), $findings);
    }

    private function manifest(Workspace $workspace): ?JsonNode
    {
        $path = $workspace->root.'/'.self::MANIFEST;

        return is_file($path) ? JsonNode::parse((string) file_get_contents($path)) : null;
    }

    /**
     * @return ?array<string, string>
     */
    private function settings(Workspace $workspace): ?array
    {
        $path = $workspace->root.'/'.self::CHECK_INI;

        if (!is_file($path)) {
            return null;
        }

        $settings = parse_ini_file($path, false, INI_SCANNER_NORMAL);

        if (false === $settings) {
            return null;
        }

        $texts = [];

        foreach ($settings as $name => $value) {
            $texts[(string) $name] = is_scalar($value) ? (string) $value : '';
        }

        return $texts;
    }

    /**
     * @return list<string>
     */
    private function versionFindings(JsonNode $manifest): array
    {
        $required = $manifest->get('config')->get('platform')->get('php')->text();

        if ('' === $required || version_compare($this->runtime->version, $required, '>=')) {
            return [];
        }

        return ['PHP '.$this->runtime->version.' is older than the platform version '.$required.' in '.self::MANIFEST];
    }

    /**
     * @return list<string>
     */
    private function extensionFindings(JsonNode $manifest): array
    {
        $findings = [];

        foreach (array_keys($manifest->get('require')->entries()) as $package) {
            if (!str_starts_with($package, 'ext-') || $this->runtime->hasExtension(substr($package, 4))) {
                continue;
            }

            $findings[] = 'PHP extension '.substr($package, 4).' is required by '.self::MANIFEST.' but not loaded';
        }

        return $findings;
    }

    /**
     * @param array<string, string> $settings
     *
     * @return list<string>
     */
    private function settingFindings(array $settings): array
    {
        $findings = [];

        foreach ($settings as $name => $expected) {
            $actual = $this->runtime->setting($name);

            if ($actual === $expected) {
                continue;
            }

            $findings[] = 'PHP setting '.$name.' is '.($actual ?? 'unset').' but '.self::CHECK_INI.' says '.$expected;
        }

        return $findings;
    }
}
