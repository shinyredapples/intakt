<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Domain;

// The one home of the status GitHub answers when it refuses the credentials, so the token exchange and the API calls cannot drift apart.
final class RefusedCredentials
{
    public const int STATUS = 401;
}
