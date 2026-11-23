<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\PhpStan\BareSerializerFormatRule;
use Intakt\Gates\PhpStan\SerializerCallArguments;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\VariadicPlaceholder;
use PHPStan\Analyser\CollectedDataEmitter;
use PHPStan\Analyser\DependencyTracker;
use PHPStan\Analyser\NodeCallbackInvoker;
use PHPStan\Analyser\Scope;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * @extends PhpStanRuleTestCase<BareSerializerFormatRule>
 */
#[CoversClass(BareSerializerFormatRule::class)]
#[CoversClass(SerializerCallArguments::class)]
final class BareSerializerFormatRuleTest extends PhpStanRuleTestCase
{
    private const int FIRST_CASE_LINE = 11;

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function rejectedGroups(): iterable
    {
        yield 'serializer calls' => [["\$serializer->serialize(\$data, 'json')", "\$serializer->deserialize(\$data, \\stdClass::class, 'json')", "\$maybe?->serialize(\$data, 'json')", "new \\Symfony\\Component\\Serializer\\Serializer()->serialize(\$data, 'json')"]];
        yield 'named format arguments' => [["\$serializer->serialize(\$data, format: 'json')", "\$serializer->deserialize(format: 'json', type: \\stdClass::class, data: \$data)", "\$encoder->supportsEncoding(format: 'json')"]];
        yield 'normalizers, encoders and decoders' => [["\$normalizer->normalize(\$data, 'json')", "\$denormalizer->denormalize(\$data, \\stdClass::class, 'json')", "\$encoder->encode(\$data, 'json')", "\$decoder->decode('{}', 'json')"]];
        yield 'format support checks' => [["\$normalizer->supportsNormalization(\$data, 'json')", "\$denormalizer->supportsDenormalization(\$data, \\stdClass::class, 'json')", "\$encoder->supportsEncoding('json')", "\$decoder->supportsDecoding('json')"]];
        yield 'braced names and unpacked arguments' => [["\$serializer->{'serialize'}(\$data, 'json')", "\$serializer->serialize(...[\$data, 'json'])", "\$serializer->serialize(...['data' => \$data, 'format' => 'json'])", "\$serializer->serialize(\$data, ...['json'])"]];
        yield 'supported types of both normalizer contracts' => [["\$normalizer->getSupportedTypes('json')", "\$denormalizer->getSupportedTypes('json')", "\$normalizer->getSupportedTypes(format: 'json')", "\$denormalizer->getSupportedTypes(format: 'json')"]];
        yield 'unpacked arguments as PHP passes them' => [["\$serializer->serialize(...\$data, format: 'json')", "\$serializer->serialize(...[\$data, '1' => 'json'])", "\$serializer->serialize(...[...[\$data], 'json'])", "\$serializer->serialize(...[\$data, \\Symfony\\Component\\Serializer\\Encoder\\JsonEncoder::FORMAT, 1 => 'json'])", "\$serializer->serialize(...[\$data], ...['json'])"]];
        yield 'an override that renames the format parameter' => [["\$renamed->serialize(format: \$data, encoding: 'json')", "\$renamed->serialize(\$data, 'json')"]];
    }

    /**
     * @param list<string> $expressions
     */
    #[DataProvider('rejectedGroups')]
    public function testABareJsonSerializerFormatFailsStaticAnalysis(array $expressions): void
    {
        $findings = $this->ruleFindingsIn($this->sandboxReturning($expressions));

        foreach (array_keys($expressions) as $index) {
            self::assertStringContainsString('src/Report.php:'.(self::FIRST_CASE_LINE + $index * 5).' intakt.bareSerializerFormat', $findings, $expressions[$index]);
        }
    }

    public function testAFormatWrittenAsTheEncoderConstantPassesStaticAnalysis(): void
    {
        $findings = $this->ruleFindingsIn($this->sandboxReturning([
            '$serializer->serialize($data, \Symfony\Component\Serializer\Encoder\JsonEncoder::FORMAT)',
            '$decoder->decode(\'{"json": true}\', \Symfony\Component\Serializer\Encoder\JsonEncoder::FORMAT)',
            '$normalizer->getSupportedTypes(\Symfony\Component\Serializer\Encoder\JsonEncoder::FORMAT)',
            '$denormalizer->getSupportedTypes(format: \Symfony\Component\Serializer\Encoder\JsonEncoder::FORMAT)',
        ]));

        self::assertSame('', $findings);
    }

    public function testANullsafeCallWithABareJsonFormatGivesExactlyOneFinding(): void
    {
        $findings = $this->ruleFindingsIn($this->sandboxReturning(["\$maybe?->serialize(\$data, 'json')"]));

        self::assertSame(1, substr_count($findings, 'intakt.bareSerializerFormat'), $findings);
        self::assertStringContainsString('src/Report.php:'.self::FIRST_CASE_LINE.' intakt.bareSerializerFormat', $findings);
    }

    public function testASerializerReachedThroughANullsafeChainGivesExactlyOneFindingPerCall(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('src/Holder.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace Intakt\\Sample;\n\nuse Symfony\\Component\\Serializer\\SerializerInterface;\n\nfinal readonly class Holder\n{\n    public function __construct(public SerializerInterface \$serializer)\n    {\n    }\n}\n");
        $sandbox->write('src/Chains.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace Intakt\\Sample;\n\nfinal readonly class Chains\n{\n    /**\n     * @return list<mixed>\n     */\n    public function check(?Holder \$holder, Holder \$required, mixed \$data): array\n    {\n        return [\n            \$holder?->serializer->serialize(\$data, 'json'),\n            \$required->serializer->serialize(\$data, 'json'),\n            \$holder?->serializer->serialize(\$data, \\Symfony\\Component\\Serializer\\Encoder\\JsonEncoder::FORMAT),\n        ];\n    }\n}\n");

        $findings = $this->ruleFindingsIn($sandbox);

        self::assertStringContainsString('src/Chains.php:15 intakt.bareSerializerFormat', $findings);
        self::assertStringContainsString('src/Chains.php:16 intakt.bareSerializerFormat', $findings);
        self::assertSame(2, substr_count($findings, 'intakt.bareSerializerFormat'), $findings);
    }

    public function testANamedFormatIsJudgedAgainstEachReceiverOfAUnion(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('src/First.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace Intakt\\Sample;\n\nuse Symfony\\Component\\Serializer\\SerializerInterface;\n\nabstract class First implements SerializerInterface\n{\n    public function serialize(mixed \$data, string \$format, array \$context = []): string\n    {\n        return \$format;\n    }\n}\n");
        $sandbox->write('src/Second.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace Intakt\\Sample;\n\nuse Symfony\\Component\\Serializer\\SerializerInterface;\n\nabstract class Second implements SerializerInterface\n{\n    public function serialize(mixed \$format, string \$data, array \$context = []): string\n    {\n        return \$data;\n    }\n}\n");
        $sandbox->write('src/UnionCall.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace Intakt\\Sample;\n\nuse Symfony\\Component\\Serializer\\Encoder\\JsonEncoder;\n\nfinal readonly class UnionCall\n{\n    /**\n     * @return list<string>\n     */\n    public function check(First|Second \$serializer): array\n    {\n        return [\n            \$serializer->serialize(data: JsonEncoder::FORMAT, format: 'json'),\n            \$serializer->serialize(format: JsonEncoder::FORMAT, data: 'json'),\n            \$serializer->serialize(data: JsonEncoder::FORMAT, format: JsonEncoder::FORMAT),\n        ];\n    }\n}\n");

        $findings = $this->ruleFindingsIn($sandbox);

        self::assertStringContainsString('src/UnionCall.php:17 intakt.bareSerializerFormat', $findings);
        self::assertStringContainsString('src/UnionCall.php:18 intakt.bareSerializerFormat', $findings);
        self::assertSame(2, substr_count($findings, 'intakt.bareSerializerFormat'), $findings);
    }

    public function testANullsafeCallWithTheEncoderConstantPassesStaticAnalysis(): void
    {
        $findings = $this->ruleFindingsIn($this->sandboxReturning([
            '$maybe?->serialize($data, \Symfony\Component\Serializer\Encoder\JsonEncoder::FORMAT)',
        ]));

        self::assertSame('', $findings);
    }

    public function testAnUnpackedJsonThatPhpDoesNotPassAsTheFormatPassesStaticAnalysis(): void
    {
        $findings = $this->ruleFindingsIn($this->sandboxReturning([
            "\$serializer->serialize(...[\$data, 'json', 1 => \\Symfony\\Component\\Serializer\\Encoder\\JsonEncoder::FORMAT])",
            "\$serializer->serialize(...[\$data, 'json', '1' => \\Symfony\\Component\\Serializer\\Encoder\\JsonEncoder::FORMAT])",
            "\$serializer->serialize(...[...['json'], \\Symfony\\Component\\Serializer\\Encoder\\JsonEncoder::FORMAT])",
            "\$serializer->serialize(...\$data, format: \\Symfony\\Component\\Serializer\\Encoder\\JsonEncoder::FORMAT, context: ['json'])",
            "\$serializer->serialize(...\$data, ...['json'])",
            "\$serializer->serialize(...[5 => \$data, 'json', 6 => \\Symfony\\Component\\Serializer\\Encoder\\JsonEncoder::FORMAT])",
            "\$serializer->serialize(...[5 => \$data, ...['json'], 6 => \\Symfony\\Component\\Serializer\\Encoder\\JsonEncoder::FORMAT])",
            "\$serializer->serialize(...[\$data, \$data => 'json'])",
        ]));

        self::assertSame('', $findings);
    }

    public function testAnUnpackedArrayThatAddsAnElementAfterTheMaximumIntegerKeyIsUnreadableAndNoFinding(): void
    {
        $findings = $this->ruleFindingsIn($this->sandboxReturning([
            "\$serializer->serialize(...[9223372036854775807 => \$data, 'json'])",
            "\$serializer->serialize(...['9223372036854775807' => \$data, 'json'])",
            "\$serializer->serialize(...[9223372036854775807 => \$data, ...[\$data, 'json']])",
            "\$serializer->serialize(...[\\PHP_INT_MAX => \$data, 'json'])",
        ]));

        self::assertSame('', $findings);
    }

    public function testAJsonStringThatIsNotTheFormatArgumentPassesStaticAnalysis(): void
    {
        $findings = $this->ruleFindingsIn($this->sandboxReturning([
            "\$serializer->serialize('json', \\Symfony\\Component\\Serializer\\Encoder\\JsonEncoder::FORMAT)",
            "\$serializer->serialize(\$data, \\Symfony\\Component\\Serializer\\Encoder\\JsonEncoder::FORMAT, ['json' => true])",
            "\$serializer->deserialize(\$data, 'json', \\Symfony\\Component\\Serializer\\Encoder\\JsonEncoder::FORMAT)",
            "['json' => \$data, 'body' => '{\"format\": \"json\"}']",
        ]));

        self::assertSame('', $findings);
    }

    public function testAJsonArgumentToAClassThatIsNotASerializerPassesStaticAnalysis(): void
    {
        $findings = $this->ruleFindingsIn($this->sandboxReturning([
            "\$other->serialize(\$data, 'json')",
            "\$other->serialize(\$data, format: 'json')",
        ]));

        self::assertSame('', $findings);
    }

    public function testAJsonArgumentThatIsNotTheRenamedFormatParameterPassesStaticAnalysis(): void
    {
        $findings = $this->ruleFindingsIn($this->sandboxReturning([
            "\$renamed->serialize(format: 'json', encoding: \\Symfony\\Component\\Serializer\\Encoder\\JsonEncoder::FORMAT)",
            "\$renamed->serialize('json', \\Symfony\\Component\\Serializer\\Encoder\\JsonEncoder::FORMAT)",
        ]));

        self::assertSame('', $findings);
    }

    public function testAFirstClassCallableOfAFormatMethodPassesStaticAnalysis(): void
    {
        $findings = $this->ruleFindingsIn($this->sandboxReturning([
            '$serializer->serialize(...)',
            '$encoder->encode(...)',
            '$other->serialize(...)',
            '\Symfony\Component\Serializer\Serializer::serialize(...)',
        ]));

        self::assertSame('', $findings);
    }

    public function testAFirstClassCallableOfASameNamedMethodIsNoFinding(): void
    {
        $node = new MethodCall(new Variable('other'), new Identifier('encode'), [new VariadicPlaceholder()]);

        $scope = self::createStubForIntersectionOfInterfaces([Scope::class, CollectedDataEmitter::class, DependencyTracker::class, NodeCallbackInvoker::class]);
        self::assertTrue($scope instanceof Scope && $scope instanceof CollectedDataEmitter && $scope instanceof DependencyTracker && $scope instanceof NodeCallbackInvoker);

        $errors = new BareSerializerFormatRule()->processNode($node, $scope);

        self::assertSame([], $errors);
    }

    public function testABareJsonFormatPassedToTheParentSerializerFailsStaticAnalysis(): void
    {
        $sandbox = $this->sandboxReturning([]);
        $sandbox->write('src/JsonShortcut.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace Intakt\\Sample;\n\nclass JsonShortcut extends \\Symfony\\Component\\Serializer\\Serializer\n{\n    public function asJson(mixed \$data): string\n    {\n        return parent::serialize(\$data, 'json');\n    }\n\n    public function asConstant(mixed \$data, string \$format): string\n    {\n        return parent::serialize(\$data, \$format).self::serialize('json', \$format);\n    }\n}\n");

        $findings = $this->ruleFindingsIn($sandbox);

        self::assertStringContainsString('src/JsonShortcut.php:11 intakt.bareSerializerFormat', $findings);
        self::assertStringNotContainsString('src/JsonShortcut.php:16', $findings);
    }

    protected function ruleFor(string $root): BareSerializerFormatRule
    {
        return new BareSerializerFormatRule();
    }

    /**
     * @param list<string> $expressions
     */
    private function sandboxReturning(array $expressions): Sandbox
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('src/Other.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace Intakt\\Sample;\n\nfinal readonly class Other\n{\n    public function serialize(mixed \$data, string \$format): string\n    {\n        return \$format;\n    }\n}\n");
        $sandbox->write('src/RenamedSerializer.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace Intakt\\Sample;\n\nuse Symfony\\Component\\Serializer\\SerializerInterface;\n\nfinal readonly class RenamedSerializer implements SerializerInterface\n{\n    public function serialize(mixed \$format, string \$encoding, array \$context = []): string\n    {\n        return \$encoding;\n    }\n\n    public function deserialize(mixed \$data, string \$type, string \$format, array \$context = []): mixed\n    {\n        return \$data;\n    }\n}\n");
        $sandbox->write('src/Report.php', $this->classReturning($expressions));

        return $sandbox;
    }

    /**
     * @param list<string> $expressions
     */
    private function classReturning(array $expressions): string
    {
        $parameters = implode(', ', [
            '\Symfony\Component\Serializer\SerializerInterface $serializer',
            '?\Symfony\Component\Serializer\SerializerInterface $maybe',
            '\Symfony\Component\Serializer\Normalizer\NormalizerInterface $normalizer',
            '\Symfony\Component\Serializer\Normalizer\DenormalizerInterface $denormalizer',
            '\Symfony\Component\Serializer\Encoder\EncoderInterface $encoder',
            '\Symfony\Component\Serializer\Encoder\DecoderInterface $decoder',
            'Other $other',
            'RenamedSerializer $renamed',
            'mixed $data',
        ]);
        $methods = array_map(
            static fn (int $index, string $expression): string => "    public function case{$index}({$parameters}): mixed\n    {\n        return {$expression};\n    }\n",
            array_keys($expressions),
            $expressions,
        );

        return "<?php\n\ndeclare(strict_types=1);\n\nnamespace Intakt\\Sample;\n\nfinal readonly class Report\n{\n".implode("\n", $methods)."}\n";
    }
}
