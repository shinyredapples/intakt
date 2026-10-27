<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

use Opis\JsonSchema\Errors\ValidationError;
use JsonException;
use NoDiscard;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;

/**
 * @phpstan-type EvidenceShape array{probe: string, output: string}
 * @phpstan-type FindingShape array{id: string, severity: string, file: ?string, line: int|float|null, symbol: ?string, text: string, fixable_by: string, repeat: bool, requirement: ?string, evidence: ?EvidenceShape}
 * @phpstan-type VerdictShape array{verdict: string, findings: list<FindingShape>, resolved: list<string>, follow_ups: list<string>}
 */
final readonly class VerdictParser
{
    private const string SCHEMA = __DIR__.'/verdict.schema.json';

    #[NoDiscard('a verdict that is not read approves nothing')]
    public function parse(string $output): Verdict
    {
        try {
            $document = json_decode($output, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            return Verdict::invalidOutput('it is not JSON ('.$exception->getMessage().')');
        }

        $violation = $this->schemaViolation($document);
        if (null !== $violation) {
            return Verdict::invalidOutput($violation);
        }

        /** @var VerdictShape $verdict */
        $verdict = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        if ($this->hasLineBeyondAnInteger($verdict)) {
            return Verdict::invalidOutput('a finding line is not a whole number in range');
        }

        return $this->verdictFrom($verdict);
    }

    private function schemaViolation(mixed $document): ?string
    {
        $error = new Validator()->validate($document, (string) file_get_contents(self::SCHEMA))->error();
        if (!$error instanceof ValidationError) {
            return null;
        }

        $messages = new ErrorFormatter()->formatKeyed($error);
        $path = (string) array_key_first($messages);

        return $path.': '.$messages[$path][0];
    }

    /**
     * @param VerdictShape $verdict
     */
    private function hasLineBeyondAnInteger(array $verdict): bool
    {
        // Checked here because JSON Schema counts 12.0 and 1e30 as integers while PHP decodes them as floats.
        return array_any($verdict['findings'], static fn (array $finding): bool => is_float($finding['line']));
    }

    /**
     * @param VerdictShape $verdict
     */
    private function verdictFrom(array $verdict): Verdict
    {
        return new Verdict(
            Decision::from($verdict['verdict']),
            array_map($this->findingFrom(...), $verdict['findings']),
            $verdict['resolved'],
            $verdict['follow_ups'],
        );
    }

    /**
     * @param FindingShape $finding
     */
    private function findingFrom(array $finding): Finding
    {
        return new Finding(
            id: $finding['id'],
            severity: Severity::from($finding['severity']),
            file: $finding['file'],
            line: null === $finding['line'] ? null : (int) $finding['line'],
            symbol: $finding['symbol'],
            text: $finding['text'],
            fixableBy: FixableBy::from($finding['fixable_by']),
            repeat: $finding['repeat'],
            requirement: $finding['requirement'],
            evidence: null === $finding['evidence'] ? null : new Evidence($finding['evidence']['probe'], $finding['evidence']['output']),
        );
    }
}
