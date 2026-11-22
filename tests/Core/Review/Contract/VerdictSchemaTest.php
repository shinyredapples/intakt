<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Contract;

use Intakt\Core\Review\Infrastructure\ReviewerAnswer\Verdict;
use JsonSchema\Validator;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\StructuredOutput\ResponseFormatFactory;

// Covers nothing because the schema file is only an oracle: the classes are the source of truth (docs/plan.md, T1).
#[CoversNothing]
final class VerdictSchemaTest extends TestCase
{
    private const string SCHEMA = __DIR__.'/verdict.schema.json';

    public function testTheSchemaFileIsTheSchemaStructuredOutputSendsForTheVerdictClass(): void
    {
        $sent = new ResponseFormatFactory()->create(Verdict::class)['json_schema']['schema'];

        self::assertSame(json_decode((string) file_get_contents(self::SCHEMA), true, flags: JSON_THROW_ON_ERROR), $sent);
    }

    #[DataProviderExternal(AcceptedVerdicts::class, 'cases')]
    public function testTheSchemaFileAcceptsWhatTheClassesAccept(string $output): void
    {
        self::assertSame([], $this->schemaErrors($output));
    }

    #[DataProviderExternal(BrokenFieldRules::class, 'cases')]
    public function testTheSchemaFileRefusesWhatTheClassesRefuse(string $output, string $why, string ...$otherWordings): void
    {
        self::assertNotSame([], $this->schemaErrors($output), 'The classes refuse this output ('.implode(' or ', [$why, ...$otherWordings]).') but the schema file accepts it.');
    }

    /**
     * @return list<mixed>
     */
    private function schemaErrors(string $output): array
    {
        $document = json_decode($output, flags: JSON_THROW_ON_ERROR);
        $validator = new Validator();
        $validator->validate($document, json_decode((string) file_get_contents(self::SCHEMA)));

        return array_values($validator->getErrors());
    }
}
