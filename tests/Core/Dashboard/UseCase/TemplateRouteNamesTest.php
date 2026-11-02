<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use Intakt\Core\Dashboard\Contract\DashboardRoutes;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class TemplateRouteNamesTest extends TestCase
{
    public function testNoDashboardTemplateSpellsARouteNameOutByHand(): void
    {
        $templates = glob(dirname(__DIR__, 4).'/templates/dashboard/*.twig');

        self::assertIsArray($templates);
        self::assertNotSame([], $templates);
        foreach ($templates as $template) {
            self::assertDoesNotMatchRegularExpression(
                '/[\'"]'.preg_quote(DashboardRoutes::PREFIX, '/').'/',
                (string) file_get_contents($template),
                basename($template).' names a route by string instead of a DashboardRoutes constant',
            );
        }
    }
}
