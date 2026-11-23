<?php

declare(strict_types=1);

namespace Intakt\Gates;

final readonly class EnvironmentGate implements Gate
{
    private const string MANIFEST = 'composer.json';
    private const string CHECK_INI = 'config/gates/php/check.ini';
    private const string LOAD_HINT = 'Load the profile by adding config/gates/php to PHP_INI_SCAN_DIR, after the directories PHP already scans (php --ini lists them), for example PHP_INI_SCAN_DIR=/etc/php/8.5/cli/conf.d:config/gates/php';
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
    private const array BOOLEAN_SETTINGS = ['assert.active', 'assert.exception', 'display_errors', 'display_startup_errors'];

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
     * @return ?array<string, array{written: string, loaded: string}>
     */
    private function settings(Workspace $workspace): ?array
    {
        $path = $workspace->root.'/'.self::CHECK_INI;

        if (!is_file($path)) {
            return null;
        }

        $written = parse_ini_file($path, false, INI_SCANNER_RAW);
        $loaded = parse_ini_file($path, false, INI_SCANNER_NORMAL);

        if (false === $written || false === $loaded) {
            return null;
        }

        $settings = [];

        foreach ($written as $name => $value) {
            $text = is_scalar($value) ? (string) $value : '';
            $settings[(string) $name] = ['written' => $text, 'loaded' => $this->loaded($text, $loaded[$name] ?? null)];
        }

        return $settings;
    }

    // PHP loads On as 1 and E_ALL & ~E_DEPRECATED as a number; a ${VAR} would take its value from whoever runs the gate.
    private function loaded(string $written, mixed $loaded): string
    {
        return str_contains($written, '${') || !is_scalar($loaded) ? $written : (string) $loaded;
    }

    /**
     * @param array<string, array{written: string, loaded: string}> $settings
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

            if (null !== $actual && $this->comparable($name, $actual) === $this->comparable($name, $expected['loaded'])) {
                continue;
            }

            $findings[] = 'PHP setting '.$name.' is '.($actual ?? 'unset').' but '.self::CHECK_INI.' says '.$expected['written'];
        }

        return $findings;
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

    // ini_set() keeps a word such as On as given, while the ini parser turns it into 1 or an empty string.
    private function comparable(string $name, string $value): string
    {
        if (!in_array($name, self::BOOLEAN_SETTINGS, true)) {
            return $value;
        }

        return match (strtolower($value)) {
            '', '0', 'off', 'no', 'false', 'none' => '0',
            '1', 'on', 'yes', 'true' => '1',
            default => $value,
        };
    }

    /**
     * @param array<string, array{written: string, loaded: string}> $settings
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
}
