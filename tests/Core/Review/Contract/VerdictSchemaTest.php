<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Contract;

use Intakt\Core\Review\Contract\Verdict;
use JsonSchema\Validator;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\StructuredOutput\ResponseFormatFactory;

// The schema file is the test oracle the plan asks for: the classes are the source of truth, the file mirrors them.
#[CoversNothing]
final class VerdictSchemaTest extends TestCase
{
    private const string SCHEMA = __DIR__.'/verdict.schema.json';

    public function testTheSchemaFileIsTheSchemaStructuredOutputSendsForTheVerdictClass(): void
    {
        $sent = new ResponseFormatFactory()->create(Verdict::class)['json_schema']['schema'];

        self::assertSame(json_decode((string) file_get_contents(self::SCHEMA), true, flags: JSON_THROW_ON_ERROR), $sent);
    }

    public function testTheSchemaFileAcceptsTheDesignsExampleVerdict(): void
    {
        self::assertSame([], $this->schemaErrors(VerdictDocument::fromTheDesign()->json()));
    }

    #[DataProviderExternal(BrokenFieldRules::class, 'cases')]
    public function testTheSchemaFileRefusesWhatTheClassesRefuse(string $output, string $why): void
    {
        self::assertNotSame([], $this->schemaErrors($output), 'The classes refuse this output ('.$why.') but the schema file accepts it.');
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
