<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\PhpLanguage;

use Intakt\Plugin\Language\Contract\Language;
use Intakt\Plugin\PhpLanguage\Infrastructure\PhpLanguage;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Bundle\FrameworkBundle\Console\Application;

// The plugin as the container wires it, so the owner's rules and the upstream tag reader reach the command.
#[CoversNothing]
final class LanguageOnTheContainerTest extends KernelTestCase
{
    public function testTheLanguageIsThePhpPlugin(): void
    {
        self::assertInstanceOf(PhpLanguage::class, self::getContainer()->get(Language::class));
    }

    public function testTheDecideCommandIsRegistered(): void
    {
        $command = new Application(self::bootKernel())->find('intakt:dependencies:decide');

        self::assertSame('intakt:dependencies:decide', $command->getName());
    }
}
