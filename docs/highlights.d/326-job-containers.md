## Symfony components

| HttpClient over the Docker socket | `Core/Job` talks to the Docker Engine API through an `HttpClientInterface` bound to the daemon's unix socket (`bindto`), never the docker command; tested with `MockHttpClient` and a fake Engine | in progress | T44 (#326), `src/Core/Job/Infrastructure/DockerEngineApi.php` |
