<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\SymfonyAiVendor\Infrastructure;

use ArrayObject;
use Closure;
use Intakt\Core\Ai\Contract\KeyKind;
use Intakt\Core\Ai\Contract\ProviderKey;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\SymfonyAiKeyCheck;
use Intakt\Plugin\Vendor\Contract\KeyCheckOutcome;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\AI\Platform\Exception\AuthenticationException;

#[CoversClass(SymfonyAiKeyCheck::class)]
#[UsesClass(ProviderKey::class)]
#[UsesClass(KeyKind::class)]
final class SymfonyAiKeyCheckTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function keys(): iterable
    {
        yield 'anthropic' => ['sk-ant-'.self::BODY];
        yield 'openai' => ['sk-'.self::BODY];
        yield 'hugging face' => ['hf_'.self::BODY];
    }

    private const string BODY = 'abcdefgh12345678';

    #[DataProvider('keys')]
    public function testAKeyTheScriptedProviderAnswersIsAccepted(string $key): void
    {
        $outcome = $this->check($key, new ArrayObject(), static fn (): string => 'ok');

        self::assertSame(KeyCheckOutcome::Accepted, $outcome);
    }

    #[DataProvider('keys')]
    public function testAKeyTheProviderRejectsWithAnAuthenticationErrorIsRefused(string $key): void
    {
        $outcome = $this->check($key, new ArrayObject(), static fn (): never => throw new AuthenticationException('Invalid key '.$key));

        self::assertSame(KeyCheckOutcome::Refused, $outcome);
    }

    public function testAnUnreachableProviderLeavesTheKeyNotChecked(): void
    {
        $outcome = $this->check('sk-ant-'.self::BODY, new ArrayObject(), static fn (): never => throw new RuntimeException('connection refused'));

        self::assertSame(KeyCheckOutcome::NotChecked, $outcome);
    }

    public function testTheCheckMakesOneCallWithAOneTokenCapOnTheCheapestModel(): void
    {
        $calls = new ArrayObject();

        $this->check('sk-ant-'.self::BODY, $calls, static fn (): string => 'ok');

        self::assertSame([['claude-haiku-5-5', ['max_output_tokens' => 1]]], $calls->getArrayCopy());
    }

    /**
     * @param ArrayObject<int, array{string, array<mixed>}> $calls
     * @param Closure(): (string|never)                     $answer
     */
    private function check(string $key, ArrayObject $calls, Closure $answer): KeyCheckOutcome
    {
        return new SymfonyAiKeyCheck(new ScriptedKeyedPlatforms($calls, $answer))->check(ProviderKey::recognise($key));
    }
}
