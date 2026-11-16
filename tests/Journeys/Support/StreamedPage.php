<?php

declare(strict_types=1);

namespace Intakt\Tests\Journeys\Support;

use DOMDocument;
use DOMNode;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Mercure\Update;

// Applies what the hub was sent to the page a listener holds, as Turbo would, because no browser runs in a journey and the page must change without a reload.
final readonly class StreamedPage
{
    /**
     * @param list<Update> $updates
     */
    public function after(Crawler $page, string $topic, array $updates): Crawler
    {
        foreach ($updates as $update) {
            if (in_array($topic, $update->getTopics(), true)) {
                $this->applyEachStreamOf($update, $page);
            }
        }

        return $page;
    }

    private function applyEachStreamOf(Update $update, Crawler $page): void
    {
        foreach (new Crawler($update->getData())->filter('body > turbo-stream')->each(static fn (Crawler $stream): Crawler => $stream) as $stream) {
            $selector = $stream->attr('targets') ?? '#'.$stream->attr('target');

            // Nothing changes when the target is absent, because Turbo drops such a stream.
            foreach ($page->filter($selector) as $target) {
                $this->apply((string) $stream->attr('action'), $this->templateOf($stream), $target);
            }
        }
    }

    private function templateOf(Crawler $stream): ?DOMNode
    {
        // Parsed inside a template, because only there a table row keeps its cells.
        return new Crawler('<template>'.$stream->filter('template')->html('').'</template>')->filter('template')->getNode(0);
    }

    private function apply(string $action, ?DOMNode $template, DOMNode $target): void
    {
        match ($action) {
            'remove' => $target->parentNode?->removeChild($target),
            'append' => $this->appendTo($target, $template),
            'update' => $this->updateOf($target, $template),
            'replace' => $this->replaceOf($target, $template),
            default => null,
        };
    }

    private function appendTo(DOMNode $target, ?DOMNode $template): void
    {
        foreach ($this->importedChildrenOf($template, $target) as $child) {
            $target->appendChild($child);
        }
    }

    private function updateOf(DOMNode $target, ?DOMNode $template): void
    {
        while ($target->firstChild instanceof DOMNode) {
            $target->removeChild($target->firstChild);
        }

        $this->appendTo($target, $template);
    }

    private function replaceOf(DOMNode $target, ?DOMNode $template): void
    {
        foreach ($this->importedChildrenOf($template, $target) as $child) {
            $target->parentNode?->insertBefore($child, $target);
        }

        $target->parentNode?->removeChild($target);
    }

    /**
     * @return list<DOMNode>
     */
    private function importedChildrenOf(?DOMNode $template, DOMNode $target): array
    {
        $document = $target->ownerDocument;

        if (!$template instanceof DOMNode || !$document instanceof DOMDocument) {
            return [];
        }

        $children = [];

        foreach ($template->childNodes as $child) {
            $children[] = $document->importNode($child, true);
        }

        return $children;
    }
}
