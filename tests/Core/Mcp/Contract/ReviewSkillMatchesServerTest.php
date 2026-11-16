<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Mcp\Contract;

use Intakt\Kernel;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;

// Covers nothing because the contract is the skill file against the owner's server as a client lists it.
#[Group('persistence')]
#[CoversNothing]
final class ReviewSkillMatchesServerTest extends KernelTestCase
{
    private const string SKILL = __DIR__.'/../../../../skills/intakt-review/SKILL.md';

    public function testEveryToolTheReviewSkillNamesIsListedByTheOwnerServer(): void
    {
        $listed = array_keys($this->serverTools());

        foreach (array_keys($this->skillTools()) as $name) {
            self::assertContains($name, $listed, sprintf('The skill names "%s", which the server does not list.', $name));
        }
    }

    public function testEveryArgumentTheReviewSkillPassesIsInTheToolsInputSchema(): void
    {
        $server = $this->serverTools();

        foreach ($this->skillTools() as $name => $arguments) {
            $known = $server[$name] ?? [];

            foreach ($arguments as $argument) {
                self::assertContains($argument, $known, sprintf('The skill passes "%s" to %s, which its schema does not have.', $argument, $name));
            }
        }
    }

    public function testEveryToolNameInTheReviewSkillTextIsListedByTheOwnerServer(): void
    {
        $listed = array_keys($this->serverTools());
        preg_match_all('/`((?:start|review|list)_[a-z_]+)`/', $this->skill(), $matches);

        foreach ($matches[1] as $name) {
            self::assertContains($name, $listed, sprintf('The skill text names "%s", which the server does not list.', $name));
        }
    }

    public function testTheReviewSkillPassesEveryRequiredArgumentOfEveryToolItCalls(): void
    {
        $skillTools = $this->skillTools();

        foreach ($this->requiredOnServer() as $name => $required) {
            if (!isset($skillTools[$name])) {
                continue;
            }

            foreach ($required as $argument) {
                self::assertContains($argument, $skillTools[$name], sprintf('The skill leaves out "%s" of %s.', $argument, $name));
            }
        }
    }

    /**
     * @return array<string, list<string>>
     */
    private function serverTools(): array
    {
        $tools = [];

        foreach ($this->listedTools() as $name => $schema) {
            $tools[$name] = $this->argumentNames($schema);
        }

        return $tools;
    }

    /**
     * @return array<string, list<string>>
     */
    private function requiredOnServer(): array
    {
        $required = [];

        foreach ($this->listedTools() as $name => $schema) {
            $properties = $schema['properties'] ?? [];
            self::assertIsArray($properties);
            $inner = $properties['review'] ?? null;
            $required[$name] = [...$this->strings($schema['required'] ?? []), ...is_array($inner) ? $this->strings($inner['required'] ?? []) : []];
        }

        return $required;
    }

    /**
     * @return array<string, array<mixed>>
     */
    private function listedTools(): array
    {
        $kernel = self::bootKernel();
        self::assertInstanceOf(Kernel::class, $kernel);
        $client = new McpServerClient($kernel);
        $client->initialize('2025-11-25');
        $listed = McpServerClient::at($client->call('tools/list', [], 2), 'result', 'tools');
        self::assertIsArray($listed);
        $tools = [];

        foreach ($listed as $tool) {
            self::assertIsArray($tool);
            self::assertIsString($tool['name']);
            $schema = $tool['inputSchema'] ?? [];
            self::assertIsArray($schema);
            $tools[$tool['name']] = $schema;
        }

        return $tools;
    }

    /**
     * @return list<string>
     */
    private function strings(mixed $values): array
    {
        self::assertIsArray($values);

        return array_values(array_map(static fn (mixed $value): string => is_string($value) ? $value : '', $values));
    }

    /**
     * @param array<mixed> $schema
     *
     * @return list<string>
     */
    private function argumentNames(array $schema): array
    {
        $properties = $schema['properties'] ?? [];
        self::assertIsArray($properties);
        $names = array_keys($properties);

        foreach ($properties as $property) {
            if (is_array($property) && isset($property['properties']) && is_array($property['properties'])) {
                $names = [...$names, ...array_keys($property['properties'])];
            }
        }

        return array_map(strval(...), $names);
    }

    /**
     * @return array<string, list<string>>
     */
    private function skillTools(): array
    {
        $found = preg_match('/^## Tools\n(.*?)(?=^## |\z)/ms', $this->skill(), $section);
        self::assertSame(1, $found, 'The skill has no "## Tools" section.');
        $tools = [];

        foreach (explode("\n", $section[1]) as $line) {
            if (1 !== preg_match('/^- `([a-z_]+)`:(.*)$/', $line, $parts)) {
                continue;
            }

            preg_match_all('/`([A-Za-z_]+)`/', $parts[2], $arguments);
            $tools[$parts[1]] = $arguments[1];
        }

        self::assertNotSame([], $tools, 'The skill lists no tool.');

        return $tools;
    }

    private function skill(): string
    {
        $text = file_get_contents(self::SKILL);

        return false === $text ? self::fail('The skill file cannot be read.') : $text;
    }
}
