<?php

declare(strict_types=1);

namespace Intakt\Gates;

final readonly class EnvironmentGate implements Gate
{
    private const string MANIFEST = 'composer.json';
    private const string CHECK_INI = 'config/gates/php/check.ini';
    private const string LOAD_HINT = 'Load the profile by adding config/gates/php to PHP_INI_SCAN_DIR (after the directories PHP already scans), for example PHP_INI_SCAN_DIR="$(php --ini | sed -n \'s/^Scan for additional .ini files in: //p\'):config/gates/php"';
    private const array ALLOWED_SETTINGS = [
        'zend.assertions',
        'assert.exception',
        'assert.active',
        'error_reporting',
        'display_errors',
        'display_startup_errors',
        'memory_limit',
        'max_execution_time',
        'precision',
        'serialize_precision',
        'date.timezone',
        'default_charset',
    ];

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

        $settingFindings = $this->settingFindings($settings);
        $hint = [] === $settingFindings ? [] : [self::LOAD_HINT];
        $findings = [...$this->versionFindings($manifest), ...$this->extensionFindings($manifest), ...$this->unlistedFindings($settings), ...$settingFindings, ...$hint];

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

        $settings = parse_ini_file($path, false, INI_SCANNER_RAW);

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
            if (!in_array($name, self::ALLOWED_SETTINGS, true)) {
                continue;
            }

            $actual = $this->runtime->setting($name);

            if ($actual === $this->resolved($expected)) {
                continue;
            }

            $findings[] = 'PHP setting '.$name.' is '.($actual ?? 'unset').' but '.self::CHECK_INI.' says '.$expected;
        }

        return $findings;
    }

    /**
     * @param array<string, string> $settings
     *
     * @return list<string>
     */
    private function unlistedFindings(array $settings): array
    {
        $findings = [];

        foreach (array_keys($settings) as $name) {
            if (!in_array($name, self::ALLOWED_SETTINGS, true)) {
                $findings[] = 'PHP setting '.$name.' in '.self::CHECK_INI.' is not an allowed setting';
            }
        }

        return $findings;
    }

    // The raw text keeps E_ALL as written; the running PHP reports the number.
    private function resolved(string $expected): string
    {
        return 1 === preg_match('/^E_[A-Z_]+$/', $expected) && defined($expected) ? (string) constant($expected) : $expected;
    }
}
