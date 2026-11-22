<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\UseCase\PlayDemo;

use Intakt\Core\Dashboard\Domain\ProviderStep;

enum DemoReadiness: string
{
    case NotComposeDemo = 'not-compose-demo';
    case NoRecording = 'no-recording';
    case Ready = 'ready';
    case Played = 'played';

    public function sentence(): string
    {
        return match ($this) {
            self::NotComposeDemo => 'The demo run plays only on the compose demo, and INTAKT_COMPOSE_SERVICE is empty on this install.',
            self::NoRecording => 'No demo recording is installed: set INTAKT_DEMO_RECORDING to a file written by intakt:demo:record.',
            self::Ready => 'Press the button to play the recorded run; the caption at the bottom of each page names the stage it is in.',
            self::Played => 'The demo run is in the ledger. To play it again, remove it with intakt:demo:remove and start the compose demo again.',
        };
    }

    // The first-run page's line under its title, in plain words: no variable, file or command, because a visitor there has no terminal.
    // It points to the key form only while the provider step shows one, so it never names a form that is not on the page.
    public function firstRunLine(ProviderStep $providers): string
    {
        return match ($this) {
            self::Ready => 'Two AI labs read a change, then the owl decides what to fix.',
            self::NotComposeDemo, self::NoRecording => $providers->offersKeyForm()
                ? 'This install has no recorded review. Add a key below to run one live.'
                : 'This install has no recorded review. A live review can be started from the cards below.',
            self::Played => 'The recorded review has played. Its rounds are in the review list.',
        };
    }
}
