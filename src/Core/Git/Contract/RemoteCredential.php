<?php

declare(strict_types=1);

namespace Intakt\Core\Git\Contract;

use SensitiveParameter;
use SensitiveParameterValue;

// The password sits in a SensitiveParameterValue, so print_r, var_dump and an exception's trace show an empty object instead of it.
final readonly class RemoteCredential
{
    private SensitiveParameterValue $password;

    public function __construct(
        public string $user,
        #[SensitiveParameter]
        string $password,
    ) {
        $this->password = new SensitiveParameterValue($password);
    }

    // Basic authentication in the form git's http.extraHeader sends, because a header reaches neither git's command line nor the remote's address.
    public function authorizationHeader(): string
    {
        return 'Authorization: Basic '.base64_encode($this->user.':'.$this->password->getValue());
    }
}
