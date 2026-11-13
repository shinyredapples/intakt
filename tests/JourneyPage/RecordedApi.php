<?php

declare(strict_types=1);

namespace Intakt\Tests\JourneyPage;

final readonly class RecordedApi implements GitHubApi
{
    public function __construct(private string $folder)
    {
    }

    public function pages(JourneyResource $resource): string
    {
        $file = $this->folder.'/'.$resource->value.'.json';
        $json = is_file($file) ? file_get_contents($file) : false;

        return false === $json ? throw new JourneyInputFailed('no recorded answer '.$resource->value.'.json in '.$this->folder) : $json;
    }
}
