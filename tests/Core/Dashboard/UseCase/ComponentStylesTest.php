<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Tests\Support\WebTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Twig\Environment;

// Booting a kernel only where Twig renders or the asset server answers, because the CSS rules read as text need no ledger or page of their own.
#[CoversNothing]
final class ComponentStylesTest extends WebTestCase
{
    private const string GALLERY = __DIR__.'/component-gallery.html.twig';

    private const string STYLES = __DIR__.'/../../../../assets/styles/';

    public function testTheLayoutServesTheComponentSheet(): void
    {
        self::assertStringContainsString('.btn.danger[data-armed]', $this->servedComponentSheet());
    }

    public function testTheComponentSheetUsesNoRawColor(): void
    {
        self::assertDoesNotMatchRegularExpression('/#[0-9a-f]{3,8}\b|\b(?:rgba?|hsla?|oklch)\(|:\s*(?:white|black)\b/i', $this->componentSheet());
    }

    public function testTheComponentSheetTakesEveryTypeSizeFromATypeToken(): void
    {
        preg_match_all('/font(?:-size)?:\s*([^;}]+)/', $this->componentSheet(), $declarations);

        self::assertNotEmpty($declarations[1]);
        foreach ($declarations[1] as $declaration) {
            self::assertDoesNotMatchRegularExpression('/\d(?:px|rem|em|pt)\b/', $declaration, $declaration);
        }
    }

    public function testAmberAppearsOnlyInNeedsYouRules(): void
    {
        $amberSelectors = array_keys(array_filter($this->rulesOf($this->componentSheet()), static fn (string $body): bool => str_contains($body, '--amber')));

        self::assertNotEmpty($amberSelectors);
        foreach ($amberSelectors as $selector) {
            foreach (explode(',', $selector) as $part) {
                // The current step of a Working now path takes the orange ring (owner's UX2 decision), the one amber outside Needs you.
                if ('.path li.now::after' === trim($part)) {
                    continue;
                }
                self::assertStringContainsString('.needs', $part, $selector);
            }
        }
    }

    public function testEveryClassTheComponentsRenderIsStyled(): void
    {
        [$components, $base] = $this->sheets('components', 'base');

        foreach ($this->renderedClasses() as $class) {
            self::assertMatchesRegularExpression('/\.'.preg_quote($class, '/').'(?![\w-])/', $components.$base, $class);
        }
    }

    public function testTheComponentsThatNeedScriptNameTheirControllers(): void
    {
        $controllers = $this->gallery()->filter('#gallery [data-controller]')->each(static fn (Crawler $element): string => (string) $element->attr('data-controller'));

        self::assertSame(['toast'], $controllers);
    }

    public function testTheSearchOverlayIsAnOpaqueRaisedPanelWithRadius18OverTheScrim(): void
    {
        $rules = $this->rulesOf($this->componentSheet());

        self::assertMatchesRegularExpression('/background:\s*var\(--backdrop\)/', $rules['.overlay'] ?? '');
        self::assertMatchesRegularExpression('/display:\s*none/', $rules['.overlay[hidden]'] ?? '');
        $palette = $rules['.palette'] ?? '';
        self::assertMatchesRegularExpression('/background:\s*var\(--raised\)/', $palette);
        self::assertMatchesRegularExpression('/border-radius:\s*var\(--r-overlay\)/', $palette);
        self::assertMatchesRegularExpression('/border:\s*1px solid var\(--hairline\)/', $palette);
        self::assertStringNotContainsString('opacity', $palette);
    }

    public function testTheSearchPanelMovesInWithoutFading(): void
    {
        self::assertMatchesRegularExpression('/\.overlay:not\(\[hidden\]\) \.palette \{ animation: rise /', $this->componentSheet());
        self::assertMatchesRegularExpression('/@keyframes rise \{ from \{ transform: translateY\(8px\); \} \}/', $this->componentSheet());
    }

    public function testTheSearchPanelDoesNotMoveUnderReducedMotion(): void
    {
        self::assertMatchesRegularExpression('/@media \(prefers-reduced-motion: reduce\) \{\s*\.overlay:not\(\[hidden\]\) \.palette \{ animation: none; \}/', $this->componentSheet());
    }

    public function testTheSelectedSearchResultTakesTheSelectedTint(): void
    {
        self::assertMatchesRegularExpression('/background:\s*var\(--selected\)/', $this->rulesOf($this->componentSheet())['.palette .res[aria-selected="true"]'] ?? '');
    }

    public function testEverySearchResultIsATargetAtLeast44PxTallAtEveryWidth(): void
    {
        // Anchored to an unindented rule, because the phone override inside its media block would otherwise satisfy it.
        self::assertMatchesRegularExpression('/^\.palette \.res \{[^}]*min-height:\s*44px/m', $this->componentSheet());
    }

    public function testTheSearchFieldKeepsTheVisibleFocusOutline(): void
    {
        $rules = $this->rulesOf($this->componentSheet());

        self::assertDoesNotMatchRegularExpression('/outline:\s*(?:none|0)\b/', $rules['.palette .q input'] ?? '');
        self::assertArrayHasKey('.palette .q input:focus-visible', $rules);
    }

    public function testTheSearchFieldsFocusRingFollowsItsRoundedShapeOutsideItsText(): void
    {
        $rules = $this->rulesOf($this->componentSheet());

        self::assertMatchesRegularExpression('/border-radius:\s*var\(--r-btn\)/', $rules['.palette .q input'] ?? '');
        self::assertMatchesRegularExpression('/outline-offset:\s*0;/', $rules['.palette .q input:focus-visible'] ?? '');
    }

    public function testTheSearchFieldPadsItsTextAwayFromItsFocusRing(): void
    {
        self::assertMatchesRegularExpression('/padding:\s*0 var\(--s8\)/', $this->rulesOf($this->componentSheet())['.palette .q input'] ?? '');
    }

    public function testOnPhonesTheSearchFieldKeepsTheInputFloorOf48Px(): void
    {
        preg_match_all('/@media \(max-width: 760px\) \{(.*?)\n\}/s', $this->componentSheet(), $phone);

        self::assertMatchesRegularExpression('/height:\s*48px/', $this->rulesOf(implode("\n", $phone[1]))['.overlay .palette .q input'] ?? '');
    }

    public function testTheBreadcrumbSeparatesItsCrumbsWithAMutedSlash(): void
    {
        $rules = $this->rulesOf($this->componentSheet());

        self::assertMatchesRegularExpression('/content:\s*"\/"/', $rules['.crumbs li + li::before'] ?? '');
        self::assertMatchesRegularExpression('/color:\s*var\(--muted\)/', $rules['.crumbs li + li::before'] ?? '');
    }

    public function testACompactBannerShowsOnlyItsToggleAtEveryWidth(): void
    {
        $sheet = $this->componentSheet();
        $rules = $this->rulesOf(substr($sheet, 0, (int) strpos($sheet, '@media (max-width: 760px)')));

        self::assertMatchesRegularExpression('/display:\s*none/', $rules['.banner.compact > :not(.banner-toggle)'] ?? '');
        self::assertMatchesRegularExpression('/display:\s*flex/', $rules['.banner.compact > .banner-toggle'] ?? '');
    }

    public function testAFigureKindTooltipIsPlacedAgainstTheViewportSoAScrollingTableCannotClipIt(): void
    {
        $rule = $this->rulesOf($this->componentSheet())['.figure-kind .figure-kind-tip'] ?? '';

        self::assertMatchesRegularExpression('/position:\s*fixed/', $rule);
        self::assertMatchesRegularExpression('/position-try-fallbacks:\s*flip-block/', $rule);
    }

    public function testAFigureKindTooltipGrowsAwayFromTheLeftEdgeAndFlipsBeforeLeavingTheViewport(): void
    {
        $rule = $this->rulesOf($this->componentSheet())['.figure-kind .figure-kind-tip'] ?? '';

        self::assertMatchesRegularExpression('/position-area:\s*bottom span-right/', $rule);
        self::assertMatchesRegularExpression('/position-try-fallbacks:\s*flip-block, flip-inline, flip-block flip-inline;/', $rule);
    }

    public function testAFigureKindTooltipShowsOnHoverOnly(): void
    {
        $rules = $this->rulesOf($this->componentSheet());

        self::assertMatchesRegularExpression('/display:\s*block/', $rules['.figure-kind:hover .figure-kind-tip'] ?? '');
        self::assertSame([], array_filter(array_keys($rules), static fn (string $selector): bool => str_contains($selector, '.figure-kind:focus')));
    }

    public function testOnPhonesAStepperButtonIs48By46(): void
    {
        $rules = $this->rulesOf($this->phoneBlock());

        self::assertMatchesRegularExpression('/width:\s*48px;\s*height:\s*46px/', $rules['.stepper button'] ?? '');
    }

    public function testOnPhonesTheCopyFieldWrapsTheCommandAboveAFullWidthButton48PxTall(): void
    {
        $rules = $this->rulesOf($this->phoneBlock());

        self::assertMatchesRegularExpression('/flex-wrap:\s*wrap/', $rules['.copy'] ?? '');
        self::assertMatchesRegularExpression('/white-space:\s*normal/', $rules['.copy code'] ?? '');
        self::assertMatchesRegularExpression('/width:\s*100%;\s*height:\s*48px/', $rules['.copy .btn'] ?? '');
    }

    public function testASwitchInputFillsItsTrackWithExplicitDimensions(): void
    {
        // A checkbox is a replaced box with a natural size: inset alone leaves it about 13 px square, so the track needs explicit width and height.
        $sheet = $this->componentSheet();
        $rules = $this->rulesOf(substr($sheet, 0, (int) strpos($sheet, '@media (max-width: 760px)')));

        self::assertMatchesRegularExpression('/width:\s*100%;\s*height:\s*100%/', $rules['.switch input'] ?? '');
    }

    public function testOnPhonesASwitchIs52By32WithAHitArea44PxTall(): void
    {
        preg_match('/@media \(max-width: 760px\) \{(.*?)\n\}/s', $this->componentSheet(), $phone);
        $rules = $this->rulesOf($phone[1] ?? '');

        self::assertMatchesRegularExpression('/width:\s*52px;\s*height:\s*32px/', $rules['.switch'] ?? '');
        self::assertMatchesRegularExpression('/top:\s*-6px;\s*width:\s*100%;\s*height:\s*44px/', $rules['.switch input'] ?? '');
    }

    public function testOnPhonesASettingRowWrapsItsControlsAcrossTheFullWidth(): void
    {
        $rules = $this->rulesOf($this->phoneBlock());

        self::assertMatchesRegularExpression('/flex-wrap:\s*wrap;\s*gap:\s*var\(--s\d+\)/', $rules['.srow'] ?? '');
        self::assertMatchesRegularExpression('/width:\s*100%;\s*flex-wrap:\s*wrap/', $rules['.srow .ctl'] ?? '');
        self::assertMatchesRegularExpression('/flex:\s*1\b/', $rules['.srow .ctl .select'] ?? '');
    }

    public function testOnPhonesTheHistoryLedgerStacksEachShownRowWithItsHeaderOnlyClipped(): void
    {
        $rules = $this->rulesOf($this->phoneBlock());

        self::assertMatchesRegularExpression('/clip-path:\s*inset\(50%\)/', $rules['.ledger.history thead'] ?? '');
        self::assertMatchesRegularExpression('/display:\s*grid;\s*grid-template-columns:\s*minmax\(0, 1fr\)/', $rules['.ledger.history tr:not([hidden], [data-filtered-out])'] ?? '');
    }

    public function testNoPaddingMarginOrGapHoldsARawLengthOf4PxOrMore(): void
    {
        preg_match_all('/(?:padding|margin|gap)(?:-[a-z]+)?:\s*([^;}]+)/', (string) preg_replace('~/\*.*?\*/~s', '', $this->componentSheet()), $declarations);

        self::assertNotEmpty($declarations[1]);
        foreach ($declarations[1] as $declaration) {
            // A calc() may add the component's own sizes, because an indent lines up with an icon, so only its spacing must come from a token.
            preg_match_all('/calc\((?:[^()]|\([^()]*\))*\)/', $declaration, $calcs);
            foreach ($calcs[0] as $calc) {
                self::assertStringContainsString('var(--s', $calc, $declaration);
            }
            $outsideCalc = (string) preg_replace('/calc\((?:[^()]|\([^()]*\))*\)/', '', $declaration);
            self::assertDoesNotMatchRegularExpression('/(?<![\w.])(?:[4-9]|\d{2,})(?:\.\d+)?(?:px|rem|em)\b/', $outsideCalc, $declaration);
        }
    }

    private function phoneBlock(): string
    {
        preg_match('/@media \(max-width: 760px\) \{(.*?)\n\}/s', $this->componentSheet(), $phone);

        return $phone[1] ?? '';
    }

    private function componentSheet(): string
    {
        return $this->sheets('components')[0];
    }

    /**
     * @return list<string>
     */
    private function sheets(string ...$names): array
    {
        return array_values(array_map(static function (string $name): string {
            $sheet = file_get_contents(self::STYLES.$name.'.css');
            self::assertIsString($sheet, $name);

            return $sheet;
        }, $names));
    }

    private function servedComponentSheet(): string
    {
        $browser = self::createClient(server: ['HTTP_HOST' => 'localhost:7780']);
        self::getContainer()->set(Ledger::class, new DashboardLedger());
        $page = $browser->request('GET', '/reviews');
        self::assertResponseIsSuccessful();

        return $this->servedAsset($browser, (string) $page->filter('link[rel="stylesheet"][href*="/components-"]')->attr('href'));
    }

    private function servedAsset(KernelBrowser $browser, string $address): string
    {
        $browser->request('GET', $address);
        self::assertResponseIsSuccessful();

        // Read from the internal response, because the asset server answers with a BinaryFileResponse, whose getContent() is empty.
        return $browser->getInternalResponse()->getContent();
    }

    /**
     * @return array<string, string>
     */
    private function rulesOf(string $sheet): array
    {
        preg_match_all('/([^{}]+)\{([^{}]*)\}/', (string) preg_replace('~/\*.*?\*/~s', '', $sheet), $rules, PREG_SET_ORDER);

        return array_column(array_map(static fn (array $rule): array => [trim($rule[1]), $rule[2]], $rules), 1, 0);
    }

    /**
     * @return list<string>
     */
    private function renderedClasses(): array
    {
        $classes = $this->gallery()->filter('#gallery [class]')->each(static fn (Crawler $element): array => explode(' ', trim((string) $element->attr('class'))));
        $classes = array_values(array_unique(array_merge(...$classes)));
        self::assertNotEmpty($classes);

        return $classes;
    }

    private function gallery(): Crawler
    {
        if (!self::$booted) {
            self::bootKernel();
        }

        // Set again, because the browser's last request rebooted the kernel and dropped the ledger the base layout reads.
        self::getContainer()->set(Ledger::class, new DashboardLedger());

        $twig = self::getContainer()->get(Environment::class);
        self::assertInstanceOf(Environment::class, $twig);

        return new Crawler($twig->createTemplate((string) file_get_contents(self::GALLERY))->render());
    }
}
