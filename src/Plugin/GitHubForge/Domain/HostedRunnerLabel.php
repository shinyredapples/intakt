<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Domain;

// GitHub's standard hosted runner labels; any other label can name a runner someone keeps, so it asks.
enum HostedRunnerLabel: string
{
    case UbuntuLatest = 'ubuntu-latest';
    case Ubuntu2404 = 'ubuntu-24.04';
    case Ubuntu2204 = 'ubuntu-22.04';
    case Ubuntu2404Arm = 'ubuntu-24.04-arm';
    case Ubuntu2204Arm = 'ubuntu-22.04-arm';
    case WindowsLatest = 'windows-latest';
    case Windows2025 = 'windows-2025';
    case Windows2022 = 'windows-2022';
    case Windows11Arm = 'windows-11-arm';
    case MacosLatest = 'macos-latest';
    case Macos15 = 'macos-15';
    case Macos14 = 'macos-14';
    case Macos13 = 'macos-13';
}
