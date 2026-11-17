<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\UseCase\PlayDemo;

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
}
